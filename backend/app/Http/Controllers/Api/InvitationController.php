<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendUserInvitationEmailJob;
use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\CompanyUser;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Emite invitaciones para que nuevos usuarios se unan a una empresa con un rol específico.
 *
 * store(): valida que el email no tenga membresía activa ni una invitación pendiente no expirada.
 * El token de invitación tiene 64 caracteres aleatorios y expira a los 7 días.
 * Un usuario ya miembro no puede ser re-invitado; retorna 422.
 * La expiración es fija en este controlador; para hacerla configurable usar config/roles.php.
 *
 * resend(): permite reenviar manualmente el correo de una invitación pendiente
 * cuando el destinatario no lo recibió o lo perdió. Idempotente por construcción
 * del Job (CA-7): si ya hubo envío exitoso reciente, el job omite el reenvío.
 * Limpia `email_sent_at` para que el job pueda procesar de nuevo.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Lista las invitaciones pendientes (status=pending) de la empresa activa.
     * El frontend la usa para renderizar la sección "Invitaciones pendientes"
     * en /users con acciones reenviar/cancelar. Solo trae lo accionable:
     * pending no expiradas. Las expiradas/accepted no entran (ya no se puede
     * hacer nada con ellas vía UI; el historial vive en audit_logs).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');

        $invitations = CompanyInvitation::query()
            ->where('company_nit', $companyNit)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with(['role:id,name,color'])
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'company_role_id', 'expires_at', 'email_sent_at', 'created_at']);

        return response()->json([
            'data' => $invitations->map(fn (CompanyInvitation $i) => [
                'id' => $i->id,
                'email' => $i->email,
                'role_id' => $i->company_role_id,
                'role_name' => $i->role?->name,
                'role_color' => $i->role?->color,
                'expires_at' => $i->expires_at?->toIso8601String(),
                'email_sent_at' => $i->email_sent_at?->toIso8601String(),
                'created_at' => $i->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'company_role_id' => ['required', 'uuid', 'exists:company_roles,id'],
        ]);

        $role = CompanyRole::where('company_nit', $companyNit)
            ->where('id', $validated['company_role_id'])
            ->firstOrFail();

        $alreadyMember = CompanyUser::where('company_nit', $companyNit)
            ->whereHas('user', fn ($q) => $q->where('email', $validated['email']))
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'El usuario ya es miembro de esta empresa.'], 422);
        }

        $pending = CompanyInvitation::where('email', $validated['email'])
            ->where('company_nit', $companyNit)
            ->where('status', 'pending')
            ->first();

        if ($pending && ! $pending->isExpired()) {
            return response()->json(['message' => 'Ya existe una invitación pendiente para este correo.'], 422);
        }

        $invitedByUserId = $jwtPayload['sub'] ?? null;

        $invitation = DB::transaction(function () use ($companyNit, $validated, $role, $invitedByUserId, $request) {
            $invitation = CompanyInvitation::create([
                'company_nit' => $companyNit,
                'email' => $validated['email'],
                'role' => 'member',
                'company_role_id' => $role->id,
                'token' => Str::random(64),
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            $this->auditService->log(
                'invitation.sent',
                null,
                $invitation,
                [
                    'company_nit' => $companyNit,
                    'invited_email' => $validated['email'],
                    'role' => $role->name,
                    'invited_by' => $invitedByUserId,
                ],
                $request
            );

            // `after_commit: true` global en config/queue.php hace que el job
            // sólo se encole si la transacción de invitación commitea OK.
            SendUserInvitationEmailJob::dispatch(
                $invitation->id,
                $invitedByUserId !== null ? (string) $invitedByUserId : null,
            );

            return $invitation;
        });

        return response()->json([
            'message' => 'Listo. Le enviamos el correo de invitación a '.$invitation->email.'.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_name' => $role->name,
                'expires_at' => $invitation->expires_at,
            ],
        ], 201);
    }

    public function resend(Request $request, string $id): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $invitedByUserId = $jwtPayload['sub'] ?? null;

        $invitation = CompanyInvitation::where('company_nit', $companyNit)
            ->where('id', $id)
            ->firstOrFail();

        if ($invitation->status !== 'pending') {
            return response()->json(['message' => 'Solo se pueden reenviar invitaciones pendientes.'], 422);
        }

        if ($invitation->isExpired()) {
            return response()->json(['message' => 'La invitación está expirada; crea una nueva.'], 422);
        }

        // Permitir que el job vuelva a procesar el envío. El uniqueId
        // (`invitation_email:{id}`) tiene TTL de 1 h: si fue reciente, este
        // dispatch se descarta vía ShouldBeUnique; pasada esa hora, vuelve a
        // pasar y el job consultará `email_sent_at` ya en NULL.
        $invitation->forceFill(['email_sent_at' => null])->save();

        SendUserInvitationEmailJob::dispatch(
            $invitation->id,
            $invitedByUserId !== null ? (string) $invitedByUserId : null,
        );

        $this->auditService->log(
            'invitation.resent',
            null,
            $invitation,
            [
                'company_nit' => $companyNit,
                'invited_email' => $invitation->email,
                'invited_by' => $invitedByUserId,
            ],
            $request
        );

        return response()->json([
            'message' => 'Reenvío encolado. Le llegará el correo a '.$invitation->email.'.',
            'invitation' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at,
            ],
        ]);
    }

    /**
     * Cancela una invitación pendiente. Hard delete + audit log: el enum de
     * status no incluye 'cancelled' (sería pending|accepted|expired) y agregar
     * un valor al enum es ALTER TYPE invasivo. El audit log preserva el
     * historial (action=invitation.cancelled) y al borrarla, la constraint
     * "no pending duplicate" libera el slot para re-invitar al mismo email.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManagerRole($request);

        $companyNit = $request->attributes->get('active_company_nit');
        $jwtPayload = $request->attributes->get('jwt_payload');
        $actorUserId = $jwtPayload['sub'] ?? null;

        $invitation = CompanyInvitation::query()
            ->where('company_nit', $companyNit)
            ->where('id', $id)
            ->firstOrFail();

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Solo se pueden cancelar invitaciones pendientes.',
            ], 422);
        }

        // Audit ANTES del delete para que auditable_id quede grabado mientras
        // la fila aún existe (AuditService::log lee getKey() del modelo).
        $this->auditService->log(
            'invitation.cancelled',
            null,
            $invitation,
            [
                'company_nit' => $companyNit,
                'invited_email' => $invitation->email,
                'invited_role_id' => $invitation->company_role_id,
                'cancelled_by' => $actorUserId,
            ],
            $request
        );

        $email = $invitation->email;
        $invitation->delete();

        return response()->json([
            'message' => 'Invitación cancelada para '.$email.'.',
        ]);
    }

    private function authorizeManagerRole(Request $request): void
    {
        $role = $request->attributes->get('jwt_payload')['role'] ?? null;
        if (! is_array($role) || ! ($role['is_system'] ?? false)) {
            abort(403, 'No autorizado.');
        }
    }
}
