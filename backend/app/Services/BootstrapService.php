<?php

namespace App\Services;

use App\Models\Bank;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\SignedAssetUrl;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Construye el contexto compartido cliente/servidor consumido por:
 *  - El middleware HandleInertiaRequests (modo Inertia legacy).
 *  - El endpoint GET /api/v1/bootstrap (modo SPA).
 *
 * Mantiene una sola fuente de verdad para auth, empresas, sedes activas,
 * permisos y catálogos canónicos. Evita drift entre las shared props de
 * Inertia y la respuesta del endpoint API durante la migración.
 */
class BootstrapService
{
    public function __construct(
        private readonly CompanySettingsService $settingsService,
        private readonly JwtService $jwtService,
        private readonly FeaturePermissionService $featurePermissions,
        private readonly BillingService $billing,
        private readonly BranchSettingsService $branchSettings,
    ) {}

    /**
     * Datos derivados del JWT + estado fresco de BD para empresa/sedes.
     *
     * @return array{
     *   companies: array<int, array<string, mixed>>,
     *   activeCompany: array<string, mixed>|null,
     *   branches: array<int, array<string, mixed>>,
     *   activeBranch: array<string, mixed>|null,
     *   role: array<string, mixed>|null,
     *   permissions: array<int, string>,
     * }
     */
    public function buildSessionContext(Request $request): array
    {
        $companies = [];
        $activeCompany = null;
        $role = null;
        $permissions = [];
        $branches = [];
        $activeBranch = null;

        $payload = $request->attributes->get('jwt_payload');

        if (! is_array($payload)) {
            $token = $this->jwtService->extractTokenFromRequest($request);
            if (is_string($token) && $token !== '') {
                try {
                    $payload = $this->jwtService->verify($token);
                } catch (RuntimeException) {
                    $payload = null;
                }
            }
        }

        if (! is_array($payload)) {
            return compact('companies', 'activeCompany', 'branches', 'activeBranch', 'role', 'permissions');
        }

        $companies = $payload['companies'] ?? [];

        $activeCompanyNit = $payload['active_company_nit'] ?? null;
        $activeBranchId = $payload['active_branch_id'] ?? null;

        // #268 — `role` y `permissions` se recomputan EN VIVO desde BD, NO se
        // copian del payload del JWT. El token es un snapshot horneado al login:
        // si un owner edita un rol a mitad de sesión, el payload queda rancio y
        // el sidebar mostraría accesos que el backend ya rechaza (él valida en
        // vivo, devolviendo 403). Resolver acá con la MISMA fuente de verdad que
        // usa `JwtService::issue` mantiene frontend y backend alineados con un
        // simple refresh, sin esperar a re-loguear ni a que expire el token.
        $userId = $payload['sub'] ?? null;
        $user = $userId !== null ? User::find((string) $userId) : null;
        if ($user !== null) {
            $resolved = $this->featurePermissions->resolveRoleAndPermissions($user, $activeCompanyNit);
            $role = $resolved['role'];
            $permissions = $resolved['permissions'];
        }

        if ($activeCompanyNit !== null) {
            $userId = (string) ($payload['sub'] ?? '');
            $isOwner = ($role['is_system'] ?? false) === true;

            $query = Branch::query()
                ->where('company_nit', $activeCompanyNit)
                ->whereNull('archived_at');

            if (! $isOwner) {
                $query->whereHas('users', fn ($q) => $q->where('users.id', $userId));
            }

            $branches = $query
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'is_default', 'address', 'city', 'menu_qr_token'])
                ->map(fn ($b) => [
                    // id como string para alinear con el tipo TS Branch.
                    'id' => (string) $b->id,
                    'name' => $b->name,
                    'slug' => $b->slug,
                    'is_default' => (bool) $b->is_default,
                    'address' => $b->address,
                    'city' => $b->city,
                    'menu_qr_token' => $b->menu_qr_token,
                ])
                ->values()
                ->all();
        }

        if ($activeBranchId !== null) {
            $activeBranch = collect($branches)->firstWhere('id', $activeBranchId)
                ?? [
                    'id' => $activeBranchId,
                    'name' => $payload['active_branch_name'] ?? null,
                    'slug' => $payload['active_branch_slug'] ?? null,
                ];

            // Fee de domicilio de la sede activa: caja lo necesita para mostrar
            // el total real de órdenes delivery (el backend lo inyecta como línea).
            $rawFee = $this->branchSettings->get((string) $activeBranchId, 'delivery_fee');
            $activeBranch['delivery_fee'] = $rawFee !== null && $rawFee !== ''
                ? round((float) $rawFee, 2)
                : null;
        }

        if ($activeCompanyNit !== null) {
            $activeCompany = collect($companies)
                ->first(fn (array $c) => $c['nit'] === $activeCompanyNit);

            if ($activeCompany !== null) {
                // Lookup por nit (UNIQUE). La PK pasó a id uuid, así que
                // find() buscaría por uuid y nunca matchearía.
                $fresh = Company::query()
                    ->select(['nit', 'commercial_name', 'logo_path', 'status',
                        'tax_regime', 'default_tax_rate', 'default_tax_label', 'tax_included_in_price',
                        'past_due_started_at', 'expected_block_at', 'payment_blocked_at'])
                    ->where('nit', $activeCompanyNit)
                    ->first();

                if ($fresh !== null) {
                    $activeCompany['name'] = $fresh->commercial_name;
                    $activeCompany['logo_url'] = SignedAssetUrl::for($fresh->logo_path);
                    $activeCompany['status'] = $fresh->status;
                    $activeCompany['tax_regime'] = $fresh->tax_regime;
                    $activeCompany['default_tax_rate'] = (float) $fresh->default_tax_rate;
                    $activeCompany['default_tax_label'] = $fresh->default_tax_label;
                    $activeCompany['tax_included_in_price'] = (bool) $fresh->tax_included_in_price;

                    // #246 — Datos para transferir a flexyflow visibles SIEMPRE
                    // (no solo en mora). El cliente puede pagar proactivamente
                    // desde /company/settings → Facturación. Combina datos
                    // bancarios (BREB, banco, cuenta) + identificación fiscal
                    // (NIT/DV/razón social) para que el cliente sepa a quién
                    // paga y pueda diligenciar la transferencia sin error.
                    // NIT/DV vacío o ausente → null: el frontend oculta el campo
                    // (el placeholder 900000001 no debe mostrarse como NIT real).
                    $flexyNit = trim((string) config('billing.flexyflow.nit'));
                    $flexyDv = trim((string) config('billing.flexyflow.dv'));

                    $activeCompany['flexyflow_payment'] = array_merge(
                        config('billing.flexyflow_payment'),
                        [
                            'nit' => $flexyNit !== '' ? $flexyNit : null,
                            'dv' => $flexyDv !== '' ? $flexyDv : null,
                            'legal_name' => config('billing.flexyflow.legal_name'),
                            'commercial_name' => config('billing.flexyflow.commercial_name'),
                            'billing_email' => config('billing.flexyflow.billing_email'),
                            'billing_phone' => config('billing.flexyflow.billing_phone'),
                        ],
                    );

                    if (in_array($fresh->status, ['past_due', 'suspended'], true)) {
                        $activeCompany['past_due_started_at'] = $fresh->past_due_started_at?->toIso8601String();
                        $activeCompany['expected_block_at'] = $fresh->expected_block_at?->toDateString();
                        $activeCompany['payment_blocked_at'] = $fresh->payment_blocked_at?->toIso8601String();
                    }

                    // Features del plan ACTIVO (no snapshot) — gatea el sidebar
                    // y la página /company/dian sin un fetch extra a
                    // /billing/subscription. `[]` si no hay subscription activa.
                    $activeCompany['plan_features'] = $this->billing->getActiveSubscription($activeCompanyNit)?->plan?->features ?? [];
                }

                $activeCompany['brand_color'] = $this->settingsService->get(
                    $activeCompanyNit,
                    'menu_primary_color',
                    '#FF6B35'
                );
            }
        }

        return compact('companies', 'activeCompany', 'branches', 'activeBranch', 'role', 'permissions');
    }

    /**
     * Catálogos canónicos consumidos por el frontend (estados de orden,
     * métodos de pago, acciones RBAC, vinculation_status). Idéntico contrato
     * que el middleware de Inertia para que el frontend no tenga que
     * ramificar lógica según el modo de transporte.
     *
     * @return array<string, mixed>
     */
    public function buildCatalogs(): array
    {
        return [
            'orderStatuses' => [
                'all' => config('orders.all'),
                'operational' => config('orders.operational'),
                'terminal_success' => config('orders.terminal_success'),
                'terminal_failure' => config('orders.terminal_failure'),
                'kanban' => config('orders.kanban'),
                'revenue' => config('orders.revenue'),
                'labels' => config('orders.labels'),
                'badges' => config('orders.badges'),
                'category' => config('orders.category'),
            ],
            'paymentMethods' => [
                'methods' => config('payments.methods'),
                'receipt_methods' => config('payments.receipt_methods'),
                'labels' => config('payments.labels'),
                'requires_reference' => config('payments.requires_reference'),
            ],
            'rbacActions' => config('rbac.actions'),
            'employeeStatuses' => [
                'statuses' => config('employees.vinculation_statuses'),
                'absence_statuses' => config('employees.absence_statuses'),
                'labels' => config('employees.labels'),
                'badges' => config('employees.badges'),
            ],
            'vapidPublicKey' => config('notifications.web_push.vapid_public_key'),
            // Measurement ID de GA4 (publico). Vacio => null => el frontend
            // NO carga gtag.js. Se setea solo en pdn (ver config/services.php).
            'gaMeasurementId' => config('services.ga4.measurement_id') ?: null,
            // Catálogo de impresión (config/printing.php) — consumido por
            // la página company/printers (#220, antes prop server-side).
            'printingConfig' => [
                'types' => config('printing.types'),
                'connections' => config('printing.connections'),
                'paper_widths' => config('printing.paper_widths'),
            ],
            // Lista de bancos para selects de cuentas (company/settings).
            'availableBanks' => Bank::query()
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->toArray(),
            // URLs de TOS, privacidad y contrato (config/legal.php). El
            // frontend las usa en enrollment para abrir cada documento en
            // una pestaña nueva. TOS/privacidad son fijas (sitio
            // institucional); el contrato vive en el propio SPA y se resuelve
            // contra frontend_url del ambiente actual.
            'legalUrls' => [
                'terms' => config('legal.terms'),
                'privacy' => config('legal.privacy'),
                'contract' => rtrim((string) config('app.frontend_url'), '/').config('legal.contract_path'),
            ],
        ];
    }
}
