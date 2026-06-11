<?php

namespace App\Http\Controllers\Enrollment;

use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\UserEnrollmentRequest;
use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserAcceptance;
use App\Services\Account\AccountEmailChangeService;
use App\Services\AuditService;
use App\Services\Enrollment\EmployeeProvisioningService;
use App\Services\JwtService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registra los datos personales del usuario en el primer login (paso de enrollment).
 *
 * store(): solo aplica si el usuario está en status 'pending_enrollment'; retorna 422 si ya completó.
 * Registra aceptación de TOS y política de privacidad vigentes al momento del enrollment.
 * Procesa invitaciones pendientes al email del usuario y las marca como 'accepted' o 'expired'.
 * Al finalizar, emite un nuevo JWT con enrollment_step actualizado para continuar el flujo.
 * Invariante: un usuario solo puede pasar por este proceso una vez.
 */
class UserEnrollmentController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
        private readonly EmployeeProvisioningService $employeeProvisioning,
        private readonly AccountEmailChangeService $accountEmailChange,
    ) {}

    public function store(UserEnrollmentRequest $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        if (! $user->isPendingEnrollment()) {
            return response()->json(['message' => 'El usuario ya completó su enrollamiento.'], 422);
        }

        // La cédula es identidad única (una persona = una cuenta). Si ya
        // pertenece a OTRA cuenta, no es un dead-end: la persona probablemente
        // ya tiene cuenta (con otro correo) y entró con un Google nuevo. Le
        // ofrecemos mover su cuenta a este correo, confirmando por su correo
        // viejo (la cédula sola nunca alcanza — no es secreta).
        $cedula = (string) $request->validated('cedula');
        $cedulaOwner = User::query()
            ->where('cedula', $cedula)
            ->whereKeyNot($user->id)
            ->first();

        if ($cedulaOwner !== null) {
            $this->accountEmailChange->request($cedulaOwner, $user);
            $maskedEmail = $this->maskEmail($cedulaOwner->email);

            return response()->json([
                'status' => 'cedula_belongs_to_other_account',
                'masked_email' => $maskedEmail,
                'message' => "Esta cédula ya tiene una cuenta. Te enviamos un enlace a {$maskedEmail} para confirmar el cambio a este correo.",
            ], 409);
        }

        DB::transaction(function () use ($request, $user) {
            $user->update([
                'first_name' => $request->validated('first_name'),
                'last_name' => $request->validated('last_name'),
                'cedula' => $request->validated('cedula'),
                // `name` es columna generada (first_name + last_name): no se escribe.
                'status' => 'active',
            ]);

            $now = now();

            foreach (['terms', 'privacy'] as $documentType) {
                UserAcceptance::create([
                    'user_id' => $user->id,
                    'document_type' => $documentType,
                    'accepted_at' => $now,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            $this->processInvitations($user);
            $this->linkExistingEmployees($user);

            $this->auditService->log('user.enrolled', $user, $user, [
                'accepted_documents' => ['terms', 'privacy'],
                'documents_source' => 'external_wiki',
            ], $request);
        });

        $companies = $user->fresh()->companies()->get();
        $token = $this->jwtService->issue($user->fresh(), $companies);

        return response()
            ->json([
                'authenticated' => true,
                // Un usuario sin empresas (signup normal) pasa a registrar la
                // suya; uno invitado a una empresa existente ya quedó vinculado
                // en processInvitations() y no necesita crear ninguna.
                'enrollment_step' => $companies->isEmpty() ? 'company' : 'complete',
            ])
            ->withCookie($this->jwtService->buildCookie($token));
    }

    private function processInvitations(User $user): void
    {
        $pendingInvitations = CompanyInvitation::where('email', $user->email)
            ->where('status', 'pending')
            ->get();

        foreach ($pendingInvitations as $invitation) {
            if ($invitation->isExpired()) {
                $invitation->update(['status' => 'expired']);

                continue;
            }

            // company_users.company_role_id es NOT NULL. Si la invitación no
            // trae rol explícito (legacy o creada antes del campo), elegimos
            // el primer rol no-system de la empresa como fallback, o cualquier
            // rol si no hay no-system. Mismo patrón que InvitedEnrollmentController.
            $companyRoleId = $invitation->company_role_id
                ?? CompanyRole::query()
                    ->where('company_nit', $invitation->company_nit)
                    ->where('is_system', false)
                    ->value('id')
                ?? CompanyRole::query()
                    ->where('company_nit', $invitation->company_nit)
                    ->value('id');

            if ($companyRoleId === null) {
                Log::warning('UserEnrollmentController: empresa sin roles, saltando invitación', [
                    'invitation_id' => $invitation->id,
                    'company_nit' => $invitation->company_nit,
                ]);

                continue;
            }

            $user->companyMemberships()->create([
                'company_nit' => $invitation->company_nit,
                'company_role_id' => $companyRoleId,
            ]);

            $invitation->update(['status' => 'accepted']);

            $this->auditService->log('invitation.accepted', $user, $invitation);

            // Sin perfil employees el guard de turno activo en caja bloquea
            // al invitado aunque tenga la membership + permisos. Lo
            // garantizamos acá (idempotente: linkea por email o crea).
            $this->employeeProvisioning->ensureProfileFor(
                $user,
                $invitation->company_nit,
                'user_enrollment_with_invitation',
            );
        }
    }

    /**
     * Match user↔employee al enrolarse con Google. Si existe un
     * `employees` no enlazado con el mismo email del usuario en alguna empresa
     * a la que pertenece (vía company_user), vinculamos `user_id` Y
     * sincronizamos los datos personales que el user acaba de cargar
     * (nombres, apellidos, doc_number). Esto cubre el caso típico: un admin
     * pre-creó al colaborador con un placeholder, el colaborador se registra
     * con Google y su propio formulario sobreescribe la información.
     *
     * Sólo sobreescribimos campos que difieran de los del user — no
     * pisamos información HHRR ya ingresada por el admin.
     */
    private function linkExistingEmployees(User $user): void
    {
        Employee::query()
            ->whereNull('user_id')
            ->where('email', $user->email)
            ->whereIn('company_nit', $user->companyMemberships()->pluck('company_nit'))
            ->get()
            ->each(function (Employee $employee) use ($user) {
                $changes = ['user_id' => $user->id];

                if ($user->first_name && $employee->first_name !== $user->first_name) {
                    $changes['first_name'] = $user->first_name;
                }
                if ($user->last_name && $employee->last_name !== $user->last_name) {
                    $changes['last_name'] = $user->last_name;
                }
                if ($user->cedula && $employee->doc_number !== $user->cedula) {
                    $changes['doc_number'] = $user->cedula;
                }

                $employee->update($changes);

                $this->auditService->log('employee.linked_to_user', $user, $employee, [
                    'matched_by_email' => $user->email,
                    'synced_fields' => array_keys(array_diff_key($changes, ['user_id' => true])),
                ]);
            });
    }

    /**
     * Enmascara un correo para mostrarlo sin filtrarlo entero: deja la inicial
     * del local + el dominio (ej. `j•••@gmail.com`). Da una pista al usuario
     * sin revelar el correo completo de la otra cuenta.
     */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        $visible = $local !== '' ? mb_substr($local, 0, 1) : '';

        return $domain !== '' ? "{$visible}•••@{$domain}" : "{$visible}•••";
    }
}
