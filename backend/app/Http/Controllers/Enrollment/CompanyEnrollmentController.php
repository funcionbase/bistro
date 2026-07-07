<?php

namespace App\Http\Controllers\Enrollment;

use App\Exceptions\Billing\CompanyAlreadyHasActivePromoException;
use App\Exceptions\Billing\PromoCodeMaxCompaniesReachedException;
use App\Exceptions\Billing\PromoCodeNotApplicableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Enrollment\CompanyEnrollmentRequest;
use App\Jobs\SendCompanyPendingActivationOpsAlertJob;
use App\Jobs\SendCompanyRegistrationWelcomeEmailJob;
use App\Models\BillingPlan;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\CompanyRole;
use App\Models\CompanyUser;
use App\Models\CompanyWorkforceSetting;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\KdsStation;
use App\Models\PrepArea;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserAcceptance;
use App\Models\Warehouse;
use App\Services\AuditService;
use App\Services\CompanySettingsService;
use App\Services\EnrollmentProofService;
use App\Services\JwtService;
use App\Services\PromoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Crea la empresa y vincula al usuario creador como Propietario (paso company del enrollment).
 *
 * Solo aplica cuando el usuario ya está en status 'active' (completó el paso personal).
 * Crea los 3 roles del sistema a partir de config/roles.php (system_roles) con sus permisos de plantilla.
 * Registra aceptación del contrato vigente al momento del enrollment.
 * Siembra la configuración por defecto de la empresa vía CompanySettingsService::seedDefaults().
 * Emite un nuevo JWT con enrollment_step='complete' y la empresa activa.
 *
 * Persiste el documento de propiedad (evidencia) en S3 mediante
 * EnrollmentProofService dentro de la misma transacción. La empresa nace en
 * `pending_activation` (default del schema) y el workflow operativo externo
 * la transiciona a `verified` o `rejected`.
 */
class CompanyEnrollmentController extends Controller
{
    public function __construct(
        private readonly JwtService $jwtService,
        private readonly AuditService $auditService,
        private readonly CompanySettingsService $settingsService,
        private readonly EnrollmentProofService $proofService,
        private readonly PromoCodeService $promoCodeService,
    ) {}

    public function store(CompanyEnrollmentRequest $request): JsonResponse
    {
        $payload = $request->attributes->get('jwt_payload');
        $user = User::findOrFail($payload['sub']);

        // El gate de correo verificado (acceso dual) vive en
        // CompanyEnrollmentRequest::authorize() — corre ANTES de la validación
        // para que el 403 llegue limpio con code=email_not_verified.

        if (! $user->isActive()) {
            return response()->json(['message' => 'Debes completar tu enrollamiento personal primero.'], 422);
        }

        $validated = $request->validated();
        $proofFile = $request->file('proof_document');
        $company = DB::transaction(function () use ($request, $validated, $user, $proofFile) {
            $qrCodePath = null;
            if ($request->hasFile('qr_code')) {
                // El disco se resuelve dinámicamente (s3 en QA/PDN, local en dev).
                // Hardcodear 'public' rompía multi-instancia: el QR quedaba sólo
                // en la EC2 que recibió la request → 404 desde otras del ASG.
                $qrCodePath = $request->file('qr_code')->store(
                    'companies/qr-codes',
                    (string) config('filesystems.default'),
                );
            }

            $company = Company::create([
                'nit' => $validated['nit'],
                'commercial_name' => $validated['commercial_name'],
                'legal_name' => $validated['legal_name'],
                'bank_id' => $validated['bank_id'],
                'account_number' => $validated['account_number'],
                'account_type' => $validated['account_type'],
                'qr_code_path' => $qrCodePath,
                'breb_key' => $validated['breb_key'] ?? null,
                'status' => config('companies.default', 'pending_activation'),
            ]);

            // Subida del documento de propiedad. Vive en la misma
            // transacción para que un fallo al persistir la fila de
            // enrollment_proofs revierta la creación de la empresa.
            $this->proofService->store($company, $proofFile, $user);

            // 1. Crear los 3 roles del sistema con sus permisos de plantilla
            $ownerRole = null;
            foreach (config('roles.system_roles') as $roleType) {
                $role = CompanyRole::createFromTemplate($roleType, $company->nit);
                if ($roleType === 'owner') {
                    $ownerRole = $role;
                }
            }

            abort_if(! $ownerRole, 500, 'No se encontró el rol owner entre los roles del sistema.');

            // 1b. Sembrar roles operativos pre-armados: waiter/cook/cashier
            // (flujo mesa QR) + manager/accountant/marketing/inventory_manager/supervisor
            // (administrativos). is_system=false → el owner los puede renombrar,
            // ajustar permisos o eliminar desde /identities/roles.
            //
            // Si un PermissionTemplate falta para algún roleType (raro: indica que
            // el seeder no corrió), `createFromTemplate` aborta 500 y la
            // DB::transaction revierte la empresa entera. Aceptable: mejor fallar
            // rápido que dejar empresas a medio sembrar.
            foreach (config('roles.bootstrap_templates', []) as $roleType) {
                CompanyRole::createFromTemplate($roleType, $company->nit, isSystem: false);
            }

            // 2. Asignar el usuario creador al rol Propietario
            CompanyUser::create([
                'company_nit' => $company->nit,
                'user_id' => $user->id,
                'company_role_id' => $ownerRole->id,
            ]);

            // 2b. Multi-sede: toda empresa nueva nace con una sede default
            // ('principal'). El owner puede renombrar/agregar sedes desde
            // /company/branches. Sin esto, ninguna mutación operativa funcionaría
            // (branch_id es NOT NULL en todas las tablas operativas).
            // #237 — vertical de la primera sede. Si no llega, default
            // 'restaurant' (compatibilidad). El frontend lo expone como
            // selector con descripción/tooltip por opción.
            $businessTypeSlug = $validated['main_branch_business_type'] ?? 'restaurant';
            $businessType = BusinessType::find($businessTypeSlug);
            abort_if($businessType === null, 422, 'Tipo de negocio inválido.');

            $branch = Branch::create([
                'company_nit' => $company->nit,
                'name' => $validated['main_branch_name'] ?? 'Sede principal',
                'slug' => 'principal',
                'address' => $validated['main_branch_address'] ?? null,
                'city' => $validated['main_branch_city'] ?? null,
                'business_type_id' => $businessType->slug,
                'is_default' => true,
            ]);
            BranchUser::create([
                'branch_id' => $branch->id,
                'user_id' => $user->id,
                'granted_by_user_id' => $user->id,
                'granted_at' => now(),
            ]);

            // #237 — sembrar prep_areas del vertical en la sede recién creada.
            // Aplica salvo `dark_store` (sin prep_areas por diseño).
            foreach ($businessType->prep_area_defaults ?? [] as $i => $area) {
                PrepArea::create([
                    'branch_id' => $branch->id,
                    'slug' => $area['slug'],
                    'label' => $area['label'],
                    'color' => $area['color'] ?? '#64748b',
                    'icon_key' => $area['icon_key'] ?? null,
                    'display_order' => $i,
                ]);
            }

            // 2c. KDS: siembra las 4 estaciones canónicas
            // (caliente/fría/barra/fritos) para la sede default. La empresa
            // arranca con un KDS operable; el owner puede renombrar, archivar
            // o agregar estaciones desde /company/settings → KDS.
            KdsStation::seedDefaultsForBranch($company->nit, $branch->id);

            // Inventario: la sede default nace con una "Bodega principal".
            // Sin ella, crear insumos / recibir compras fallaba (404) al no
            // haber bodega destino para el stock.
            Warehouse::ensureDefaultForBranch($company->nit, $branch->id);

            // 4. Registrar invitación (opcional, para el mismo usuario)
            CompanyInvitation::create([
                'company_nit' => $company->nit,
                'email' => $user->email,
                'role' => 'owner', // Debe coincidir con el enum de la migración
                'token' => \Str::random(32),
                'status' => 'accepted',
                'expires_at' => now()->addDays(7),
            ]);

            UserAcceptance::create([
                'user_id' => $user->id,
                'document_type' => 'contract',
                'accepted_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            $this->settingsService->seedDefaults($company->nit);

            // Configuración de jornada laboral por defecto. La fila es
            // 1:1 con companies; sin ella, el módulo de planificador y los
            // reportes no tienen máximos contra los que advertir.
            CompanyWorkforceSetting::create([
                'company_nit' => $company->nit,
                'max_weekly_hours' => 48,
                'min_days_off_per_week' => 1,
                'hours_warning_mode' => 'warn',
            ]);

            // Perfil de colaborador del owner. Se crea automáticamente
            // con la información personal que el usuario llenó en el paso
            // anterior del enrolamiento (first_name, last_name, cedula). El
            // owner es indesactivable (EmployeeVinculationPolicy regla 2), así
            // que el pay_rate=0 es solo placeholder — el owner generalmente
            // no se paga sueldo desde el módulo de nómina y puede ajustarlo
            // luego desde la UI si gestiona el restaurante operativamente.
            $managerPosition = EmployeePosition::query()
                ->whereNull('company_nit')
                ->where('slug', 'manager')
                ->first();

            Employee::create([
                'company_nit' => $company->nit,
                'user_id' => $user->id,
                'primary_branch_id' => $branch->id,
                'position_id' => $managerPosition?->id,
                'doc_type' => 'CC',
                'doc_number' => $user->cedula ?? 'PENDIENTE-'.$user->id,
                'first_name' => $user->first_name ?? $user->name,
                'last_name' => $user->last_name ?? '',
                'email' => $user->email,
                'pay_type' => 'mensual',
                'pay_rate' => 0,
                'contract_type' => 'indefinido',
                'hire_date' => now()->toDateString(),
                'vinculation_status' => 'active',
            ]);

            // #246 — Crear suscripción al plan default vigente con snapshot inmutable.
            $defaultPlan = BillingPlan::default();
            if ($defaultPlan !== null) {
                Subscription::create([
                    'company_nit' => $company->nit,
                    'billing_plan_id' => $defaultPlan->id,
                    'plan_name_snapshot' => $defaultPlan->name,
                    'plan_price_snapshot' => $defaultPlan->price,
                    'plan_features_snapshot' => $defaultPlan->features ?? [],
                    'plan_tax_regime_snapshot' => $defaultPlan->tax_regime,
                    'plan_tax_rate_snapshot' => $defaultPlan->tax_rate,
                    'plan_snapshot_at' => now(),
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                ]);
            }

            // #246 — Aplicar promo code si llegó por URL `?promo=...`. Si el
            // código es inválido, NO bloqueamos el enrollment — solo loggeamos.
            $promoCodeSlug = $validated['promo_code'] ?? null;
            if ($promoCodeSlug !== null && $promoCodeSlug !== '') {
                try {
                    $promo = $this->promoCodeService->validateBySlug($promoCodeSlug);
                    $this->promoCodeService->applyToCompany(
                        $company->fresh(),
                        $promo,
                        appliedVia: 'enrollment',
                        appliedByUserId: $user->id,
                    );
                } catch (PromoCodeNotApplicableException|PromoCodeMaxCompaniesReachedException|CompanyAlreadyHasActivePromoException $e) {
                    Log::info('Enrollment promo code skipped', [
                        'company_nit' => $company->nit,
                        'code' => $promoCodeSlug,
                        'error_code' => property_exists($e, 'errorCode') ? $e->errorCode : 'UNKNOWN',
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            $this->auditService->log('company.created', $user, $company, [
                'nit' => $company->nit,
                'status' => $company->status,
                'promo_code_applied' => $promoCodeSlug !== null && $promoCodeSlug !== '',
            ], $request);

            return $company;
        });

        // Correos transaccionales post-registro (#226). Ambos son jobs con
        // ShouldBeUnique + ShouldQueue; cada uno tiene su columna de tracking
        // (welcome_email_sent_at / ops_alert_sent_at) y su audit event. El
        // after_commit:true global en config/queue.php asegura que estos
        // dispatches sólo se encolen si la transacción del enrollment
        // commitea OK.
        SendCompanyRegistrationWelcomeEmailJob::dispatch($user->id, $company->nit);
        SendCompanyPendingActivationOpsAlertJob::dispatch($company->nit, $user->id);

        $companies = $user->companies()->get();
        $token = $this->jwtService->issue($user, $companies);

        return response()
            ->json([
                'authenticated' => true,
                'company' => [
                    'nit' => $company->nit,
                    'commercial_name' => $company->commercial_name,
                    'status' => $company->status,
                ],
                'enrollment_step' => 'complete',
            ], 201)
            ->withCookie($this->jwtService->buildCookie($token));
    }
}
