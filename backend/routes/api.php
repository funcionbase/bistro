<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\ActiveCompanyController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AlertRuleController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BootstrapController;
use App\Http\Controllers\Api\BusinessContextController;
use App\Http\Controllers\Api\BusinessHoursController;
use App\Http\Controllers\Api\CancellationRequestController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartCouponController;
use App\Http\Controllers\Api\CashRegisterController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CompanySettingsController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CouponRedemptionController;
use App\Http\Controllers\Api\CouponValidationController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DeliveryMetricsController;
use App\Http\Controllers\Api\DeliveryStatusController;
use App\Http\Controllers\Api\Dian\DianDefaultRecipientController;
use App\Http\Controllers\Api\Dian\DianProviderConfigController;
use App\Http\Controllers\Api\Dian\DianRecipientsController;
use App\Http\Controllers\Api\Dian\DianResolutionController;
use App\Http\Controllers\Api\Dian\DianWebhookController;
use App\Http\Controllers\Api\Dian\ElectronicDocumentController;
use App\Http\Controllers\Api\Dian\FiscalProfileController;
use App\Http\Controllers\Api\Employees\EmployeeController;
use App\Http\Controllers\Api\Employees\EmployeePositionController;
use App\Http\Controllers\Api\Employees\MeShiftController;
use App\Http\Controllers\Api\Employees\ShiftController;
use App\Http\Controllers\Api\Employees\WorkforceReportController;
use App\Http\Controllers\Api\Employees\WorkforceSettingsController;
use App\Http\Controllers\Api\ExternalChatHandoffController;
use App\Http\Controllers\Api\ExternalChatMessageController;
use App\Http\Controllers\Api\ExternalHoursStatusController;
use App\Http\Controllers\Api\ExternalLoyaltyController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\FoodCostController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\IngredientMovementController;
use App\Http\Controllers\Api\InventoryHistoryController;
use App\Http\Controllers\Api\InventoryTransferController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\KdsController;
use App\Http\Controllers\Api\KdsDeviceTokenController;
use App\Http\Controllers\Api\KdsStationController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\LoyaltyReportController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MenuEngineeringController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderSmsFailureController;
use App\Http\Controllers\Api\OrderSyncController;
use App\Http\Controllers\Api\PaymentProofController;
use App\Http\Controllers\Api\PromoCodeController;
use App\Http\Controllers\Api\PublicLoyaltyController;
use App\Http\Controllers\Api\PurchaseAttachmentController;
use App\Http\Controllers\Api\PurchaseOrderController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\ReceiptPrintController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SesNotificationController;
use App\Http\Controllers\Api\SetupGuideController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TableAdminController;
use App\Http\Controllers\Api\TableCashierController;
use App\Http\Controllers\Api\TableSessionController;
use App\Http\Controllers\Api\UserRoleController;
use App\Http\Controllers\Api\WhatsappAccountController;
use App\Http\Controllers\Api\WhatsappVerificationController;
use App\Http\Controllers\Api\WhatsappWebhookController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Company\BranchController;
use App\Http\Controllers\Company\CompanyController;
use App\Http\Controllers\Company\PrinterController;
use App\Http\Controllers\Company\WarehouseController;
use App\Http\Controllers\Enrollment\CompanyEnrollmentController;
use App\Http\Controllers\Enrollment\EnrollmentProofController;
use App\Http\Controllers\Enrollment\InvitedEnrollmentController;
use App\Http\Controllers\Enrollment\UserEnrollmentController;
use App\Http\Controllers\Menu\MenuController;
use App\Http\Controllers\Public\TableJoinController;
use App\Http\Controllers\Public\TableMenuController;
use App\Http\Controllers\Public\TableOrderController;
use App\Http\Controllers\Public\TableResolveController;
use App\Http\Controllers\Reports\CashDrawerController;
use App\Http\Controllers\Reports\OrderReportController;
use App\Http\Controllers\Reports\PdfExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Webhooks publicos de WhatsApp Cloud API. Sin JWT: la autenticidad la
    // garantiza la firma HMAC del header X-Hub-Signature-256 + verify token.
    Route::get('webhooks/whatsapp', [WhatsappWebhookController::class, 'verify'])
        ->name('api.webhooks.whatsapp.verify');
    Route::post('webhooks/whatsapp', [WhatsappWebhookController::class, 'receive'])
        ->name('api.webhooks.whatsapp.receive');

    // Webhook publico de Amazon SES via SNS. Sin JWT: la autenticidad la
    // garantiza la firma RSA-SHA1/SHA256 del payload SNS verificada contra
    // el cert X.509 publicado por AWS (sns.<region>.amazonaws.com). Procesa
    // Bounce, Complaint, Delivery y la SubscriptionConfirmation inicial.
    // Idempotente por MessageId. Ver docs/wiki/EMAIL_SES_SETUP.md §10.
    Route::post('webhooks/ses-notifications', [SesNotificationController::class, 'receive'])
        ->name('api.webhooks.ses.receive');

    // Webhooks DIAN (#235). ÚNICO endpoint DIAN público (sin JWT).
    // Defensas:
    //  - `provider` restringido por regex a la lista cerrada (mock|factura1|siigo).
    //    Nuevos providers se agregan en este whitelist + en DianProviderFactory.
    //  - HMAC SHA-256 con webhook_secret_encrypted de la empresa (resuelto por
    //    provider_track_id) en DianWebhookController::verifySignature.
    //  - throttle:60,1 por IP — evita abuso si la firma falla repetidamente.
    //  - Whitelisted en NormalizeStrings — el body firmado no se normaliza.
    //  - Transición monotónica + Cache::lock por (provider, track_id) en el
    //    controller — eventos duplicados retornan 200 sin tocar estado.
    Route::post('webhooks/dian/{provider}', [DianWebhookController::class, 'handle'])
        ->where('provider', 'mock|factura1|siigo')
        ->middleware('throttle:60,1')
        ->name('api.webhooks.dian');

    // #246 — endpoints públicos del módulo pricing/promo (sin auth, throttle agresivo
    // contra enumeración de slugs). Lectura del plan default + preview de un
    // promo code antes de loguearse (URL `?promo=` en enrollment).
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('billing/plans/default', [PromoCodeController::class, 'defaultPlan'])
            ->name('api.billing.plans.default');
        Route::get('promo-codes/{code}/preview', [PromoCodeController::class, 'previewPublic'])
            ->where('code', '[A-Za-z0-9_-]{2,50}')
            ->name('api.promo-codes.preview.public');
    });

    // "No fui yo" del correo de verificacion. Sin JWT: el correo del owner
    // contiene el token unico, basta con validar el token. throttle:10,1
    // (#174 P0-3) protege ante reproduccion si un token llega a un atacante.
    Route::get('whatsapp/verification/reject', [WhatsappVerificationController::class, 'reject'])
        ->middleware('throttle:10,1')
        ->name('api.whatsapp.verification.reject');

    // CSP violation reporting — sin autenticación, llamado por el browser.
    // throttle:60,1 por IP (#174 P0-2) evita log-spam que infla el canal
    // single + CloudWatch; 60 reports/min son holgados para un browser real.
    Route::post('csp-report', function (Request $request) {
        $report = $request->json()->all();

        Log::channel('single')->warning('CSP Violation', [
            'report' => $report,
            'ip' => $request->ip(),
        ]);

        // #200 — además del log textual, dejar rastro en audit_logs para
        // que los analistas de seguridad puedan filtrar por
        // action='csp.violation' y agregar por documento, dominio, etc.
        // sin tener que parsear el log file.
        try {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'csp.violation',
                'auditable_type' => 'csp_report',
                'auditable_id' => null,
                'data' => json_encode(
                    ['report' => $report, 'ip' => $request->ip()],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // No bloquear el report si la BD está caída. El log textual ya
            // capturó la violación.
            Log::channel('single')->warning('CSP audit_logs insert failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->noContent();
    })->middleware('throttle:60,1')->name('api.csp-report');
    // throttle:api (#174 P1-1) — limiter por jwt.sub o IP (240/min). Cubre
    // dashboard polling y uso humano sin estorbar; flujos batch deben usar
    // endpoints especificos fuera de este grupo si superan ese techo.
    Route::middleware(['jwt', 'throttle:api'])->group(function () {
        // Bootstrap del frontend SPA (#220): emite las shared props que hoy
        // consume Inertia. NO requiere company.access — el cliente lo llama
        // antes del selector de empresa/sede para construir su contexto.
        Route::get('bootstrap', [BootstrapController::class, 'show'])->name('api.bootstrap');

        Route::get('me', [MeController::class, 'show'])->name('api.me');
        Route::delete('me', [MeController::class, 'destroy'])->name('api.me.destroy');

        // #237 — Contexto de negocio de la sede activa: vertical, capabilities,
        // labels dinámicos y prep_areas. Frontend lo carga después de seleccionar
        // empresa+sede y lo refresca al cambiar de sede activa.
        Route::middleware(['company.access', 'branch.access'])
            ->get('me/active-context', [BusinessContextController::class, 'show'])
            ->name('api.me.active-context');

        // #237 — Catálogo de verticales (autenticado). Usado por onboarding y
        // el selector de "cambiar tipo de negocio" de la sede.
        Route::get('business-types', [BusinessContextController::class, 'catalog'])
            ->name('api.business-types.index');

        // Gestión de cuenta (#220) — consumido por settings/profile y
        // settings/password del shell SPA. `updatePassword` queda
        // deshabilitado por HU #231 (acceso únicamente vía Google OAuth).
        Route::patch('account/profile', [AccountController::class, 'updateProfile'])->name('api.account.profile');
        Route::put('account/password', function (Request $request) {
            Log::info('auth.legacy_endpoint_hit', [
                'path' => $request->path(),
                'method' => 'PUT',
                'user_id' => optional($request->user())->id,
            ]);

            return response()->json([
                'message' => 'El cambio de contraseña está deshabilitado. Tu cuenta usa Google para iniciar sesión.',
                'code' => 'email_auth_disabled',
            ], 410);
        })->name('api.account.password');
        Route::post('account/delete', [AccountController::class, 'destroy'])->name('api.account.delete');

        Route::post('enrollment/user', [UserEnrollmentController::class, 'store'])
            ->name('api.enrollment.user');

        Route::post('enrollment/company', [CompanyEnrollmentController::class, 'store'])
            ->name('api.enrollment.company');

        Route::post('enrollment/invited', [InvitedEnrollmentController::class, 'store'])
            ->name('api.enrollment.invited');

        Route::prefix('auth')->group(function () {
            Route::post('select-company', [AuthController::class, 'selectCompany'])
                ->name('api.auth.select-company');

            Route::post('switch-company', [AuthController::class, 'switchCompany'])
                ->name('api.auth.switch-company');

            Route::get('branches-available', [AuthController::class, 'branchesAvailable'])
                ->name('api.auth.branches-available');

            Route::post('switch-branch', [AuthController::class, 'switchBranch'])
                ->name('api.auth.switch-branch');

            Route::post('logout', [AuthController::class, 'logout'])
                ->name('api.auth.logout');
        });

        // Lectura del estado de la empresa activa — debe seguir disponible
        // mientras la empresa esté pending_activation/rejected (#154), para que
        // el frontend pueda mostrar la pantalla "Cuenta en revisión".
        // NO se aplica `company.verified` a esta ruta a propósito.
        Route::middleware('company.access')->group(function () {
            Route::get('companies/active', [ActiveCompanyController::class, 'show'])
                ->name('api.companies.active');

            // #154: vista previa de la evidencia de propiedad subida en el
            // enrolamiento. Sólo accesible al uploader o al owner (rol de
            // sistema). Devuelve URL firmada de S3 (≤ 15 min).
            Route::get('enrollment/proof/preview', [EnrollmentProofController::class, 'preview'])
                ->name('api.enrollment.proof.preview');

            // #149 — Web Push subscriptions. NO van dentro de
            // company.verified+company.not_blocked: un user con empresa
            // past_due/suspended igual puede suscribirse para enterarse de
            // novedades (es feature de UX, no canal financiero). El throttle
            // 20/min protege contra abuso desde JS comprometido.
            Route::prefix('push')->middleware('throttle:20,1')->group(function () {
                Route::post('subscriptions', [PushSubscriptionController::class, 'store'])
                    ->middleware('permission:notifications,create')
                    ->name('api.push.subscriptions.store');
                Route::delete('subscriptions', [PushSubscriptionController::class, 'destroy'])
                    ->middleware('permission:notifications,delete')
                    ->name('api.push.subscriptions.destroy');
                Route::get('subscriptions/me', [PushSubscriptionController::class, 'index'])
                    ->middleware('permission:notifications,read')
                    ->name('api.push.subscriptions.index');
            });
        });

        // Resto de rutas de empresa: requieren acceso + verificación (#154) +
        // bloqueo selectivo por past_due prolongado (#175). El último deja pasar
        // billing/comprobantes incluso si la empresa está `suspended`.
        Route::middleware(['company.access', 'company.verified', 'company.not_blocked'])->group(function () {
            // Configuraciones de empresa — requiere permiso company.update
            Route::middleware('permission:company.update,read')->group(function () {
                Route::get('companies/settings', [CompanySettingsController::class, 'index'])
                    ->name('api.companies.settings.index');
                Route::get('companies/settings/{key}', [CompanySettingsController::class, 'show'])
                    ->name('api.companies.settings.show');
                Route::get('company', [CompanyController::class, 'show'])
                    ->name('api.company.show');
            });

            Route::patch('companies/settings', [CompanySettingsController::class, 'update'])
                ->middleware('permission:company.update,update')
                ->name('api.companies.settings.update');

            // Guía de configuración inicial (#setup-guide). RBAC validado en el
            // controller (is_system=true, excluyendo Empleado). Sin permiso nuevo.
            Route::get('company/setup-guide', [SetupGuideController::class, 'show'])
                ->name('api.company.setup-guide.show');
            Route::post('company/setup-guide/dismiss', [SetupGuideController::class, 'dismiss'])
                ->name('api.company.setup-guide.dismiss');

            Route::match(['PUT', 'POST'], 'company', [CompanyController::class, 'update'])
                ->middleware('permission:company.update,update')
                ->name('api.company.update');

            // SMS fallidos (#275 Fase 4): feedback al usuario que disparó el
            // cambio de estado cuando el SMS al cliente falla async. Self-scoped
            // (solo los del propio actor) → sin permiso nuevo, solo company.access.
            Route::get('order-sms-failures', [OrderSmsFailureController::class, 'index'])
                ->name('api.orders.smsFailures.index');
            Route::post('order-sms-failures/seen', [OrderSmsFailureController::class, 'markSeen'])
                ->name('api.orders.smsFailures.seen');

            // Sedes (multi-sede #117). Se gestionan a nivel de empresa, sin requerir branch.access.
            Route::prefix('company/branches')->group(function () {
                Route::get('/', [BranchController::class, 'index'])
                    ->name('api.company.branches.index');
                Route::post('/', [BranchController::class, 'store'])
                    ->middleware('permission:branches.manage,create')
                    ->name('api.company.branches.store');
                Route::patch('{branch}', [BranchController::class, 'update'])
                    ->middleware('permission:branches.manage,update')
                    ->name('api.company.branches.update');
                Route::delete('{branch}', [BranchController::class, 'destroy'])
                    ->middleware('permission:branches.manage,delete')
                    ->name('api.company.branches.destroy');
                Route::get('{branch}/users', [BranchController::class, 'users'])
                    ->middleware('permission:branches.assign_users,read')
                    ->name('api.company.branches.users.index');
                Route::post('{branch}/users', [BranchController::class, 'attachUser'])
                    ->middleware('permission:branches.assign_users,update')
                    ->name('api.company.branches.users.attach');
                Route::delete('{branch}/users/{userId}', [BranchController::class, 'detachUser'])
                    ->middleware('permission:branches.assign_users,update')
                    ->name('api.company.branches.users.detach');
                Route::post('{branch}/menu/copy', [BranchController::class, 'copyMenu'])
                    ->middleware('permission:branches.copy_menu,update')
                    ->name('api.company.branches.menu.copy');
                // #237 — cambio de vertical de una sede existente. Requiere
                // permiso `branches.manage,update` (mismo que update general).
                Route::post('{branch}/change-business-type', [BranchController::class, 'changeBusinessType'])
                    ->middleware('permission:branches.manage,update')
                    ->name('api.company.branches.changeBusinessType');
                Route::post('bulk-assign', [BranchController::class, 'bulkAssign'])
                    ->middleware('permission:branches.assign_users,update')
                    ->name('api.company.branches.users.bulkAssign');
                // Cajas por sede (configuración multi-caja desde gestión de empresa).
                Route::get('{branch}/cash-registers', [BranchController::class, 'cashRegisters'])
                    ->middleware('permission:branches.manage,read')
                    ->name('api.company.branches.cashRegisters.index');
                Route::post('{branch}/cash-registers', [BranchController::class, 'storeCashRegister'])
                    ->middleware('permission:cash_register.manage,create')
                    ->name('api.company.branches.cashRegisters.store');
                Route::patch('{branch}/cash-registers/{registerId}', [BranchController::class, 'updateCashRegister'])
                    ->middleware('permission:cash_register.manage,update')
                    ->name('api.company.branches.cashRegisters.update');
            });

            // Bodegas (multi-bodega #120). Subdivisiones de inventario dentro
            // de una sede. Permiso unificado warehouses.manage.
            Route::prefix('company/warehouses')->group(function () {
                Route::get('/', [WarehouseController::class, 'index'])
                    ->middleware('permission:warehouses.manage,read')
                    ->name('api.company.warehouses.index');
                Route::post('/', [WarehouseController::class, 'store'])
                    ->middleware('permission:warehouses.manage,create')
                    ->name('api.company.warehouses.store');
                Route::patch('{warehouse}', [WarehouseController::class, 'update'])
                    ->middleware('permission:warehouses.manage,update')
                    ->name('api.company.warehouses.update');
                Route::delete('{warehouse}', [WarehouseController::class, 'destroy'])
                    ->middleware('permission:warehouses.manage,delete')
                    ->name('api.company.warehouses.destroy');

                // Asignación de bodegas a sedes (#costeo-multibodega). Una
                // bodega es company-scoped y sirve a N sedes vía el pivot
                // branch_warehouses. Permiso dedicado warehouses.assign_branches
                // (config cross-sede: owner + admin por default).
                Route::post('{warehouse}/branches', [WarehouseController::class, 'assignBranch'])
                    ->middleware('permission:warehouses.assign_branches,update')
                    ->name('api.company.warehouses.branches.assign');
                Route::delete('{warehouse}/branches/{branch}', [WarehouseController::class, 'unassignBranch'])
                    ->middleware('permission:warehouses.assign_branches,update')
                    ->name('api.company.warehouses.branches.unassign');
                Route::put('{warehouse}/branches/{branch}/default', [WarehouseController::class, 'setBranchDefault'])
                    ->middleware('permission:warehouses.assign_branches,update')
                    ->name('api.company.warehouses.branches.default');
            });

            // Impresoras térmicas (comandas de cocina/barra y recibos).
            Route::prefix('company/printers')->group(function () {
                Route::get('/', [PrinterController::class, 'index'])
                    ->middleware('permission:company.update,read')
                    ->name('api.company.printers.index');
                Route::post('/', [PrinterController::class, 'store'])
                    ->middleware('permission:company.update,update')
                    ->name('api.company.printers.store');
                Route::put('{id}', [PrinterController::class, 'update'])
                    ->middleware('permission:company.update,update')
                    ->name('api.company.printers.update');
                Route::delete('{id}', [PrinterController::class, 'destroy'])
                    ->middleware('permission:company.update,update')
                    ->name('api.company.printers.destroy');
                Route::post('{id}/test', [PrinterController::class, 'test'])
                    ->middleware('permission:company.update,update')
                    ->name('api.company.printers.test');
            });

            // Colaboradores y planificador de turnos (HU #182).
            // Las mutaciones financieras (pay_rate, cambio de estado en cascada,
            // cancelación masiva) están protegidas internamente con
            // DB::transaction + AuditService::log.
            Route::prefix('employees')->group(function () {
                Route::get('/', [EmployeeController::class, 'index'])
                    ->middleware('permission:employees.read,read')
                    ->name('api.employees.index');
                Route::post('/', [EmployeeController::class, 'store'])
                    ->middleware('permission:employees.create,create')
                    ->name('api.employees.store');
                Route::get('{id}', [EmployeeController::class, 'show'])
                    ->middleware('permission:employees.read,read')
                    ->name('api.employees.show');
                Route::put('{id}', [EmployeeController::class, 'update'])
                    ->middleware('permission:employees.update,update')
                    ->name('api.employees.update');
                Route::post('{id}/archive', [EmployeeController::class, 'archive'])
                    ->middleware('permission:employees.delete,delete')
                    ->name('api.employees.archive');
                Route::post('{id}/vinculation-state', [EmployeeController::class, 'changeVinculationState'])
                    ->middleware('permission:employees.update,update')
                    ->name('api.employees.vinculationState');
                Route::get('{id}/salary', [EmployeeController::class, 'viewSalary'])
                    ->middleware('permission:employees.view_salary,read')
                    ->name('api.employees.salary');
            });

            Route::prefix('employee-positions')->group(function () {
                Route::get('/', [EmployeePositionController::class, 'index'])
                    ->middleware('permission:employees.read,read')
                    ->name('api.employeePositions.index');
                Route::post('/', [EmployeePositionController::class, 'store'])
                    ->middleware('permission:employees.create,create')
                    ->name('api.employeePositions.store');
                Route::delete('{id}', [EmployeePositionController::class, 'destroy'])
                    ->middleware('permission:employees.delete,delete')
                    ->name('api.employeePositions.destroy');
            });

            Route::prefix('shifts')->group(function () {
                Route::get('/', [ShiftController::class, 'index'])
                    ->middleware('permission:shifts.read,read')
                    ->name('api.shifts.index');
                Route::post('/', [ShiftController::class, 'store'])
                    ->middleware('permission:shifts.manage,create')
                    ->name('api.shifts.store');
                Route::post('bulk', [ShiftController::class, 'storeBulk'])
                    ->middleware('permission:shifts.manage,create')
                    ->name('api.shifts.storeBulk');
                Route::put('{id}', [ShiftController::class, 'update'])
                    ->middleware('permission:shifts.manage,update')
                    ->name('api.shifts.update');
                Route::post('{id}/cancel', [ShiftController::class, 'cancel'])
                    ->middleware('permission:shifts.manage,delete')
                    ->name('api.shifts.cancel');
                Route::post('suggest', [ShiftController::class, 'suggest'])
                    ->middleware('permission:shifts.suggest,create')
                    ->name('api.shifts.suggest');
            });

            Route::prefix('me')->group(function () {
                Route::get('shifts', [MeShiftController::class, 'shifts'])
                    ->middleware('permission:shifts.read,read')
                    ->name('api.me.shifts');
                Route::get('profile', [MeShiftController::class, 'profile'])
                    ->name('api.me.profile');
                Route::get('salary', [MeShiftController::class, 'viewSalary'])
                    ->name('api.me.salary');
            });

            // Facturación electrónica DIAN (#235).
            // - Configuración global de empresa: owner-only por seeder; admin
            //   recibe dian.config.read para consultar tokens enmascarados.
            // - Operativos (documents, recipients, print): branch.access para
            //   aislamiento por sede + permission:dian.<slug>,<action>.
            Route::prefix('dian')->group(function () {
                // Perfil fiscal del emisor (extensión a `companies`). Se edita
                // desde /company/settings → "Información" (no desde la pantalla
                // DIAN). Gateado con el feature dedicado `company.fiscal_profile`
                // (owner-only por template; admin/operativos = ----). Los roles
                // de sistema (owner/admin/employee) bypassean por is_system; el
                // feature restringe a los roles operativos (is_system=false).
                // La URL se conserva bajo `dian/` por compatibilidad con el cliente.
                Route::get('fiscal-profile', [FiscalProfileController::class, 'show'])
                    ->middleware('permission:company.fiscal_profile,read')
                    ->name('api.dian.fiscal-profile.show');
                Route::put('fiscal-profile', [FiscalProfileController::class, 'update'])
                    ->middleware('permission:company.fiscal_profile,update')
                    ->name('api.dian.fiscal-profile.update');

                Route::get('resolutions', [DianResolutionController::class, 'index'])
                    ->middleware('permission:dian.config.read,read')
                    ->name('api.dian.resolutions.index');
                Route::post('resolutions', [DianResolutionController::class, 'store'])
                    ->middleware('permission:dian.config.write,update')
                    ->name('api.dian.resolutions.store');
                Route::put('resolutions/{resolution}', [DianResolutionController::class, 'update'])
                    ->middleware('permission:dian.config.write,update')
                    ->name('api.dian.resolutions.update');
                Route::delete('resolutions/{resolution}', [DianResolutionController::class, 'destroy'])
                    ->middleware('permission:dian.config.write,update')
                    ->name('api.dian.resolutions.destroy');

                Route::get('provider-config', [DianProviderConfigController::class, 'show'])
                    ->middleware('permission:dian.config.read,read')
                    ->name('api.dian.providerConfig.show');
                Route::put('provider-config', [DianProviderConfigController::class, 'update'])
                    ->middleware('permission:dian.config.write,update')
                    ->name('api.dian.providerConfig.update');

                Route::get('default-recipient', [DianDefaultRecipientController::class, 'show'])
                    ->middleware('permission:dian.config.read,read')
                    ->name('api.dian.defaultRecipient.show');
                Route::put('default-recipient', [DianDefaultRecipientController::class, 'update'])
                    ->middleware('permission:dian.default_recipient.write,update')
                    ->name('api.dian.defaultRecipient.update');
                Route::delete('default-recipient', [DianDefaultRecipientController::class, 'destroy'])
                    ->middleware('permission:dian.default_recipient.write,update')
                    ->name('api.dian.defaultRecipient.destroy');

                // Lookup y completado del perfil DIAN del adquirente.
                Route::get('recipients/lookup', [DianRecipientsController::class, 'lookup'])
                    ->middleware(['branch.access', 'permission:dian.recipients.read,read'])
                    ->name('api.dian.recipients.lookup');
                Route::put('recipients/{contact}/dian-profile', [DianRecipientsController::class, 'update'])
                    ->middleware(['branch.access', 'permission:dian.recipients.write,update'])
                    ->name('api.dian.recipients.update');

                // Documentos electrónicos — operativos, scope branch.
                Route::middleware(['branch.access', 'branch.consolidate'])->group(function () {
                    Route::get('documents', [ElectronicDocumentController::class, 'index'])
                        ->middleware('permission:dian.documents.read,read')
                        ->name('api.dian.documents.index');
                    Route::get('documents/{document}', [ElectronicDocumentController::class, 'show'])
                        ->middleware('permission:dian.documents.read,read')
                        ->name('api.dian.documents.show');
                    Route::get('documents/{document}/xml', [ElectronicDocumentController::class, 'xml'])
                        ->middleware('permission:dian.documents.read,read')
                        ->name('api.dian.documents.xml');
                    Route::get('documents/{document}/pdf', [ElectronicDocumentController::class, 'pdf'])
                        ->middleware('permission:dian.documents.read,read')
                        ->name('api.dian.documents.pdf');

                    Route::post('documents', [ElectronicDocumentController::class, 'store'])
                        ->middleware('permission:dian.documents.emit,create')
                        ->name('api.dian.documents.store');
                    Route::post('documents/{document}/retry', [ElectronicDocumentController::class, 'retry'])
                        ->middleware('permission:dian.documents.retry,update')
                        ->name('api.dian.documents.retry');
                    Route::post('documents/{document}/credit-note', [ElectronicDocumentController::class, 'creditNote'])
                        ->middleware('permission:dian.documents.credit_note,create')
                        ->name('api.dian.documents.creditNote');
                    Route::post('documents/{document}/convert-to-fev', [ElectronicDocumentController::class, 'convertToFev'])
                        ->middleware('permission:dian.documents.emit,create')
                        ->name('api.dian.documents.convertToFev');
                    Route::post('documents/{document}/print', [ElectronicDocumentController::class, 'print'])
                        ->middleware('permission:dian.print,update')
                        ->name('api.dian.documents.print');
                });
            });

            Route::prefix('workforce-settings')->group(function () {
                Route::get('/', [WorkforceSettingsController::class, 'show'])
                    ->middleware('permission:workforce.settings,read')
                    ->name('api.workforceSettings.show');
                Route::put('/', [WorkforceSettingsController::class, 'update'])
                    ->middleware('permission:workforce.settings,update')
                    ->name('api.workforceSettings.update');
            });

            // Reportes
            // Reportes — requieren sede activa (multi-sede #117). Si el usuario tiene
            // metrics.view_all_branches puede pasar `?branch=all` (consolidado) o
            // `?branch=<uuid>` (sede ajena) — branch.consolidate intercepta el flag.
            Route::prefix('reports')->middleware(['branch.access', 'branch.consolidate', 'permission:reports.read,read'])->group(function () {
                Route::get('orders', [OrderReportController::class, 'index'])
                    ->name('api.reports.orders');

                Route::post('export', [OrderReportController::class, 'export'])
                    ->name('api.reports.export');

                Route::get('download/{token}', [OrderReportController::class, 'download'])
                    ->name('api.reports.download');

                // Cierre de caja: por defecto el día actual en TZ America/Bogota.
                Route::get('cash-drawer', [CashDrawerController::class, 'index'])
                    ->name('api.reports.cashDrawer');
                Route::get('cash-drawer/pdf', [CashDrawerController::class, 'exportPdf'])
                    ->name('api.reports.cashDrawer.pdf');

                // Historial de sesiones de caja (turnos cerrados + abierto actual).
                Route::get('cash-register/sessions', [CashRegisterController::class, 'index'])
                    ->name('api.reports.cashRegister.index');
                Route::get('cash-register/sessions/{id}', [CashRegisterController::class, 'show'])
                    ->name('api.reports.cashRegister.show');

                // Informes de colaboradores (HU #182). Permiso dedicado workforce.reports
                // dentro del mismo grupo de reports.read.
                Route::middleware('permission:workforce.reports,read')->group(function () {
                    Route::get('workforce', [WorkforceReportController::class, 'index'])
                        ->name('api.reports.workforce');
                    Route::get('workforce.csv', [WorkforceReportController::class, 'exportCsv'])
                        ->name('api.reports.workforce.csv');
                    Route::get('workforce.pdf', [WorkforceReportController::class, 'exportPdf'])
                        ->name('api.reports.workforce.pdf');
                });
            });

            // Sesión de caja (apertura/cierre/consulta del turno actual). Requiere
            // sede activa: cada sede opera su propia caja (multi-sede #117).
            Route::prefix('cash-register')->middleware('branch.access')->group(function () {
                Route::get('current', [CashRegisterController::class, 'current'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.cashRegister.current');

                // Catálogo de cajas de la sede + estado (selector multi-caja #117).
                Route::get('registers', [CashRegisterController::class, 'registers'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.cashRegister.registers.index');
                // Config de cajas: crear / renombrar / archivar. Permiso sensible
                // de sede `cash_register.manage` (admin no auto — ver Fase 3 RBAC).
                Route::post('registers', [CashRegisterController::class, 'storeRegister'])
                    ->middleware('permission:cash_register.manage,create')
                    ->name('api.cashRegister.registers.store');
                Route::patch('registers/{id}', [CashRegisterController::class, 'updateRegister'])
                    ->middleware('permission:cash_register.manage,update')
                    ->name('api.cashRegister.registers.update');

                Route::post('open', [CashRegisterController::class, 'open'])
                    ->middleware('permission:orders.create,create')
                    ->name('api.cashRegister.open');
                Route::post('close', [CashRegisterController::class, 'close'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.cashRegister.close');

                // Egresos de caja: pagos a domiciliarios, propinas distribuidas,
                // imprevistos. Append-only (sin PUT/DELETE).
                Route::post('expenses', [CashRegisterController::class, 'storeExpense'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.cashRegister.expenses.store');
                Route::get('sessions/{id}/expenses', [CashRegisterController::class, 'expensesIndex'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.cashRegister.expenses.index');
            });

            // Features — requiere poder ver roles
            Route::get('features', [FeatureController::class, 'index'])
                ->middleware('permission:roles.read,read')
                ->name('api.features.index');

            // Gestión de roles
            Route::get('roles', [RoleController::class, 'index'])
                ->middleware('permission:roles.read,read')
                ->name('api.roles.index');
            Route::post('roles', [RoleController::class, 'store'])
                ->middleware('permission:roles.create,create')
                ->name('api.roles.store');
            Route::put('roles/{id}', [RoleController::class, 'update'])
                ->middleware('permission:roles.update,update')
                ->name('api.roles.update');
            Route::delete('roles/{id}', [RoleController::class, 'destroy'])
                ->middleware('permission:roles.delete,delete')
                ->name('api.roles.destroy');

            // Gestión de usuarios y asignación de roles
            Route::get('users', [UserRoleController::class, 'index'])
                ->middleware('permission:users.read,read')
                ->name('api.users.index');
            Route::put('users/{id}/role', [UserRoleController::class, 'update'])
                ->middleware('permission:users.update,update')
                ->name('api.users.updateRole');
            Route::put('users/{id}/permissions', [UserRoleController::class, 'updatePermissions'])
                ->middleware('permission:users.update,update')
                ->name('api.users.updatePermissions');
            Route::patch('users/{id}/status', [UserRoleController::class, 'updateStatus'])
                ->middleware('permission:users.update,update')
                ->name('api.users.updateStatus');
            Route::delete('users/{id}', [UserRoleController::class, 'destroy'])
                ->middleware('permission:users.update,delete')
                ->name('api.users.destroy');

            // Invitaciones
            Route::get('invitations', [InvitationController::class, 'index'])
                ->middleware('permission:users.update,read')
                ->name('api.invitations.index');
            Route::post('invitations', [InvitationController::class, 'store'])
                ->middleware('permission:users.update,create')
                ->name('api.invitations.store');
            Route::post('invitations/{id}/resend', [InvitationController::class, 'resend'])
                ->middleware('permission:users.update,create')
                ->name('api.invitations.resend');
            Route::delete('invitations/{id}', [InvitationController::class, 'destroy'])
                ->middleware('permission:users.update,delete')
                ->name('api.invitations.destroy');

            // Métricas operativas — requieren reports.read y sede activa. Modo consolidado
            // (?branch=all) y sede ajena (?branch=<uuid>) gateados por branch.consolidate.
            // Dashboard SPA (#220) — emite las props que antes diferían en
            // Inertia. Los permisos se gatean dentro de cada build* (panel
            // sin permiso retorna null), por eso no lleva `permission:` aquí.
            Route::get('dashboard', [ApiDashboardController::class, 'data'])
                ->middleware(['branch.access', 'branch.consolidate'])
                ->name('api.dashboard');

            Route::prefix('metrics')->middleware(['branch.access', 'branch.consolidate', 'permission:reports.read,read'])->group(function () {
                Route::get('summary', [MetricsController::class, 'summary'])->name('api.metrics.summary');
                Route::get('orders/heatmap', [MetricsController::class, 'orderHeatmap'])->name('api.metrics.orders.heatmap');
                Route::get('orders/heatmap/weekly', [MetricsController::class, 'weeklyHeatmap'])->name('api.metrics.orders.heatmap.weekly');
                Route::get('items/top', [MetricsController::class, 'topItems'])->name('api.metrics.items.top');
                Route::get('carts/abandonment', [MetricsController::class, 'cartAbandonment'])->name('api.metrics.carts.abandonment');
                Route::get('sms/counts', [MetricsController::class, 'smsCounts'])->name('api.metrics.sms.counts');

                Route::get('kpis/today', [MetricsController::class, 'kpisToday'])->name('api.metrics.kpis.today');
                Route::get('orders/active', [MetricsController::class, 'activeOrders'])->name('api.metrics.orders.active');
                Route::get('dishes/ranking', [MetricsController::class, 'topDishes'])->name('api.metrics.dishes.ranking');
                Route::get('dishes/margin', [MetricsController::class, 'dishMargin'])->name('api.metrics.dishes.margin');
                Route::get('cart/abandonment', [MetricsController::class, 'abandonmentRate'])->name('api.metrics.cart.abandonment');
                Route::get('activity/heatmap', [MetricsController::class, 'activityHeatmap'])->name('api.metrics.activity.heatmap');

                // Operación offline (#140): KPIs de sincronización por período.
                Route::get('offline/operation', [MetricsController::class, 'offlineOperation'])->name('api.metrics.offline.operation');

                // Food cost (issue #113): KPIs agregados + breakdown por plato + scatter precio/costo.
                Route::get('foodcost/summary', [FoodCostController::class, 'summary'])->name('api.metrics.foodcost.summary');
                Route::get('foodcost/items/{menuItemId}/history', [FoodCostController::class, 'itemHistory'])->name('api.metrics.foodcost.item.history');

                // #114 Menu engineering: matriz popularidad x margen unitario.
                Route::get('menu-engineering', [MenuEngineeringController::class, 'matrix'])->name('api.metrics.menu-engineering');
            });

            // Multi-sede (#117): el bloque operacional siguiente requiere sede activa.
            // El BranchScope global filtra automáticamente cualquier modelo con BelongsToBranch.
            Route::middleware('branch.access')->group(function () {

                // Gestión de cupones
                Route::middleware('permission:coupons.read,read')->group(function () {
                    Route::get('coupons', [CouponController::class, 'index'])->name('api.coupons.index');
                    Route::get('coupons/{id}', [CouponController::class, 'show'])->name('api.coupons.show');
                    Route::get('coupons/{id}/redemptions', [CouponRedemptionController::class, 'index'])->name('api.coupons.redemptions.index');
                });
                Route::post('coupons', [CouponController::class, 'store'])
                    ->middleware('permission:coupons.create,create')
                    ->name('api.coupons.store');
                Route::put('coupons/{id}', [CouponController::class, 'update'])
                    ->middleware('permission:coupons.update,update')
                    ->name('api.coupons.update');
                Route::patch('coupons/{id}/status', [CouponController::class, 'status'])
                    ->middleware('permission:coupons.update,update')
                    ->name('api.coupons.status');
                Route::delete('coupons/{id}', [CouponController::class, 'destroy'])
                    ->middleware('permission:coupons.delete,delete')
                    ->name('api.coupons.destroy');

                // Horarios de operación
                Route::middleware('permission:hours.read,read')->group(function () {
                    Route::get('hours', [BusinessHoursController::class, 'index'])->name('api.hours.index');
                    Route::get('hours/status', [BusinessHoursController::class, 'status'])->name('api.hours.status');
                    Route::get('hours/exceptions', [BusinessHoursController::class, 'indexExceptions'])->name('api.hours.exceptions.index');
                });
                Route::put('hours', [BusinessHoursController::class, 'update'])
                    ->middleware('permission:hours.update,update')
                    ->name('api.hours.update');
                Route::post('hours/exceptions', [BusinessHoursController::class, 'storeException'])
                    ->middleware('permission:hours.update,create')
                    ->name('api.hours.exceptions.store');
                Route::put('hours/exceptions/{id}', [BusinessHoursController::class, 'updateException'])
                    ->middleware('permission:hours.update,update')
                    ->name('api.hours.exceptions.update');
                Route::delete('hours/exceptions/{id}', [BusinessHoursController::class, 'destroyException'])
                    ->middleware('permission:hours.update,delete')
                    ->name('api.hours.exceptions.destroy');

                // Gestión de entregas / repartidores
                Route::middleware('permission:deliveries.read,read')->group(function () {
                    Route::get('deliveries', [DeliveryController::class, 'index'])->name('api.deliveries.index');
                    Route::get('deliveries/couriers', [DeliveryController::class, 'getCouriers'])->name('api.deliveries.couriers');
                    Route::get('deliveries/metrics', [DeliveryMetricsController::class, 'index'])->name('api.deliveries.metrics');
                    Route::get('deliveries/reassign-reasons', [DeliveryController::class, 'getReassignReasons'])->name('api.deliveries.reassign-reasons');
                    // #119: vista mobile-first del domiciliario — mis entregas
                    // asignadas en la sede activa.
                    Route::get('deliveries/mine', [DeliveryController::class, 'mine'])->name('api.deliveries.mine');
                    Route::get('deliveries/{id}', [DeliveryController::class, 'show'])
                        ->name('api.deliveries.show');
                    Route::get('orders/{orderId}/available-deliverers', [DeliveryStatusController::class, 'getAvailableDeliverers'])->name('api.orders.available-deliverers');
                });
                // #119: bolsa de órdenes disponibles para auto-asignación.
                // Requiere `deliveries.self_assign` (default rol Domiciliario).
                Route::get('deliveries/available', [DeliveryController::class, 'available'])
                    ->middleware('permission:deliveries.self_assign,read')
                    ->name('api.deliveries.available');
                Route::post('deliveries', [DeliveryController::class, 'store'])
                    ->middleware('permission:deliveries.create,create')
                    ->name('api.deliveries.store');
                Route::post('deliveries/{id}/reassign', [DeliveryStatusController::class, 'reassign'])
                    ->middleware('permission:deliveries.update,update')
                    ->name('api.deliveries.reassign');
                Route::patch('deliveries/{id}/complete', [DeliveryController::class, 'complete'])
                    ->middleware('permission:deliveries.update,update')
                    ->name('api.deliveries.complete');
                Route::delete('deliveries/{id}', [DeliveryController::class, 'destroy'])
                    ->middleware('permission:deliveries.delete,delete')
                    ->name('api.deliveries.destroy');
                Route::post('orders/{orderId}/assign-courier', [DeliveryController::class, 'assignCourier'])
                    ->middleware('permission:deliveries.create,create')
                    ->name('api.orders.assign-courier');
                // #119: cambios de estado del domiciliario (auto-asignación,
                // revertir, rechazar). Throttle agresivo para evitar toggles
                // accidentales en mobile. La autorización fina (courier
                // propio o admin) vive en el controller.
                Route::middleware('throttle:30,1')->group(function () {
                    Route::post('deliveries/orders/{orderId}/self-assign', [DeliveryController::class, 'selfAssign'])
                        ->where('orderId', '[0-9]+')
                        ->middleware('permission:deliveries.self_assign,read')
                        ->name('api.deliveries.self-assign');
                    Route::put('deliveries/{id}/revert', [DeliveryController::class, 'revert'])
                        ->middleware('permission:deliveries.update,update')
                        ->name('api.deliveries.revert');
                    Route::put('deliveries/{id}/reject', [DeliveryController::class, 'reject'])
                        ->middleware('permission:deliveries.update,update')
                        ->name('api.deliveries.reject');
                });

            }); // fin del grupo branch.access (cupones/horarios/deliveries)

            // Facturación — solo owners y admins (requiere billing.read)
            Route::prefix('billing')->middleware('permission:billing.read,read')->group(function () {
                Route::get('plans', [BillingController::class, 'plans'])
                    ->name('api.billing.plans');
                Route::get('subscription', [BillingController::class, 'subscription'])
                    ->name('api.billing.subscription');
                Route::get('invoices', [BillingController::class, 'invoices'])
                    ->name('api.billing.invoices.index');
                Route::get('invoices/export.csv', [BillingController::class, 'invoicesCsv'])
                    ->name('api.billing.invoices.csv');
                Route::get('invoices/{id}', [BillingController::class, 'show'])
                    ->name('api.billing.invoices.show');
                Route::get('invoices/{id}/download', [BillingController::class, 'download'])
                    ->name('api.billing.invoices.download');

                // Comprobantes de pago manuales (#175). El POST exige billing.write
                // pero la lectura del historial usa billing.read.
                Route::get('payment-proofs', [PaymentProofController::class, 'index'])
                    ->name('api.billing.payment-proofs.index');
                Route::post('payment-proofs', [PaymentProofController::class, 'store'])
                    ->name('api.billing.payment-proofs.store');
                // Stream del archivo del comprobante para previsualización
                // inline (#193). Recibe UUID (no id numérico) para evitar
                // enumeración. Valida pertenencia a la empresa activa y
                // devuelve el blob con `Content-Disposition: inline`.
                Route::get('payment-proofs/{proof}', [PaymentProofController::class, 'show'])
                    ->where('proof', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}')
                    ->name('api.billing.payment-proofs.show');

                // #246 — Promo codes self-service. GET y preview con
                // billing.read; POST + DELETE exigen owner/admin estricto
                // (validación en el controller).
                Route::get('promo-code', [PromoCodeController::class, 'showActive'])
                    ->name('api.billing.promo-code.show');
                Route::post('promo-code/preview', [PromoCodeController::class, 'previewForCompany'])
                    ->name('api.billing.promo-code.preview');
                Route::post('promo-code', [PromoCodeController::class, 'applySelfService'])
                    ->name('api.billing.promo-code.apply');
                Route::delete('promo-code', [PromoCodeController::class, 'cancelSelfService'])
                    ->name('api.billing.promo-code.cancel');
            });

            // Exportación PDF
            Route::prefix('exports')->group(function () {
                Route::post('orders/pdf', [PdfExportController::class, 'orders'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.exports.orders.pdf');

                Route::post('orders/csv', [PdfExportController::class, 'ordersCsv'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.exports.orders.csv');

                Route::post('metrics/pdf', [PdfExportController::class, 'metrics'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.exports.metrics.pdf');

                Route::post('couriers/pdf', [PdfExportController::class, 'couriers'])
                    ->middleware('permission:deliveries.read,read')
                    ->name('api.exports.couriers.pdf');

                Route::post('coupons/pdf', [PdfExportController::class, 'coupons'])
                    ->middleware('permission:coupons.read,read')
                    ->name('api.exports.coupons.pdf');

                Route::post('billing/pdf', [PdfExportController::class, 'billing'])
                    ->middleware('permission:billing.read,read')
                    ->name('api.exports.billing.pdf');
            });

            // Kanban de órdenes — multi-sede (#117): toda mutación requiere sede activa.
            Route::middleware('branch.access')->group(function () {
                Route::get('orders', [OrderController::class, 'index'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.index');
                Route::get('orders/tables', [OrderController::class, 'tables'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.tables');
                Route::post('orders', [OrderController::class, 'store'])
                    ->middleware('permission:orders.create,create')
                    ->name('api.orders.store');
                // Sync de batch offline (#140). Idempotente por client_uuid; el
                // controller resuelve company_nit del JWT y rechaza el batch si
                // alguna orden trae un company_nit distinto (multitenant strict).
                Route::post('orders/sync-batch', [OrderSyncController::class, 'syncBatch'])
                    ->middleware('permission:orders.create,create')
                    ->name('api.orders.syncBatch');
                // Sync unificado de la caja offline-first (plan-off.md §6.1).
                // Drena el outbox (order.create, order.close, …). El permiso se
                // valida POR operación dentro del controller (un lote mezcla
                // tipos), por eso NO lleva middleware `permission:` a nivel ruta.
                Route::post('sync/batch', [SyncController::class, 'batch'])
                    ->name('api.sync.batch');
                // Constraint regex: el `{id}` solo matchea integers para que
                // Laravel NO capture rutas hermanas como `orders/pending-approvals`
                // o `orders/pending-cancellations` (que también viven bajo el
                // prefijo `orders/`). Sin esto, el routing por first-match enviaba
                // esas alertas a `OrderController::show("pending-approvals")` y
                // fallaba el banner del dashboard.
                Route::get('orders/{id}', [OrderController::class, 'show'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.show');
                Route::get('orders/{id}/receipt-escpos', [ReceiptPrintController::class, 'show'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.receipt.escpos');
                Route::patch('orders/{id}/status', [OrderController::class, 'updateStatus'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.orders.updateStatus');
                Route::post('orders/{id}/items', [OrderController::class, 'appendItems'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.orders.appendItems');
                Route::post('orders/{id}/close-with-payment', [OrderController::class, 'closeWithPayment'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.orders.closeWithPayment');
                Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.orders.cancel');
                Route::post('orders/{id}/refund', [OrderController::class, 'refund'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.orders.refund');

                // Pantalla del mesero — sesiones de mesa con QR (#191 Fase 4).
                // Lecturas y mutaciones de tandas/items/notas. La página vive en
                // `pages/orders/table-sessions/*`.
                Route::get('table-sessions', [TableSessionController::class, 'index'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.table-sessions.index');
                Route::get('orders/pending-approvals', [TableSessionController::class, 'pendingApprovals'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.pending-approvals');
                Route::get('orders/pending-cancellations', [TableSessionController::class, 'pendingCancellations'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.orders.pending-cancellations');
                Route::get('table-sessions/billable', [TableSessionController::class, 'billable'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.table-sessions.billable');
                Route::get('table-sessions/{id}', [TableSessionController::class, 'show'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.table-sessions.show');
                Route::post('table-sessions/{id}/approve-batch', [TableSessionController::class, 'approveBatch'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.approve-batch');
                Route::post('table-sessions/{id}/items/{item}/reject', [TableSessionController::class, 'rejectItem'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.reject-item');
                Route::post('table-sessions/{id}/items/{item}/cancel', [TableSessionController::class, 'cancelItem'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.cancel-item');
                Route::patch('table-sessions/{id}/items/{item}/notes', [TableSessionController::class, 'editItemNotes'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.edit-item-notes');
                Route::post('table-sessions/{id}/notes', [TableSessionController::class, 'addNote'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.add-note');
                Route::post('table-sessions/{id}/close-empty', [TableSessionController::class, 'closeEmpty'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.close-empty');
                Route::post('table-sessions/{id}/accepts-new-guests', [TableSessionController::class, 'toggleAcceptsNewGuests'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.table-sessions.accepts-new-guests');

                Route::post('cancellation-requests/{id}/resolve', [CancellationRequestController::class, 'resolve'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.cancellation-requests.resolve');

                // Kitchen Display System (#191 Fase 5 + #115 estaciones).
                // Tickets = order_items con status approved|in_kitchen|ready.
                // Forward-only transitions. CRUD estándar: kds.read para ver,
                // kds.update para operar. Sigue el patrón canónico del catálogo.
                Route::get('kds/tickets', [KdsController::class, 'index'])
                    ->middleware('permission:kds,read')
                    ->name('api.kds.tickets.index');
                Route::patch('kds/tickets/{item}/mark-in-kitchen', [KdsController::class, 'markInKitchen'])
                    ->middleware('permission:kds,update')
                    ->name('api.kds.tickets.mark-in-kitchen');
                Route::patch('kds/tickets/{item}/mark-ready', [KdsController::class, 'markReady'])
                    ->middleware('permission:kds,update')
                    ->name('api.kds.tickets.mark-ready');
                Route::patch('kds/tickets/{item}/mark-served', [KdsController::class, 'markServed'])
                    ->middleware('permission:kds,update')
                    ->name('api.kds.tickets.mark-served');

                // #115 — Estaciones KDS: lectura para selector de menú abierta
                // a cualquier rol con `kds.read` (cook + manager + admin etc.).
                Route::get('kds/stations', [KdsStationController::class, 'index'])
                    ->middleware('permission:kds,read')
                    ->name('api.kds.stations.index');

                // #115 — Gestión admin de estaciones y device-tokens.
                // Sensible de sede: default solo owner; admin requiere
                // asignación manual (mismo patrón que cash_register
                // .bypass_switch_lock / inventory.transfer_cross_branch).
                Route::prefix('company/kds')->group(function () {
                    Route::post('stations', [KdsStationController::class, 'store'])
                        ->middleware('permission:kds_stations,create')
                        ->name('api.company.kds.stations.store');
                    Route::patch('stations/{id}', [KdsStationController::class, 'update'])
                        ->middleware('permission:kds_stations,update')
                        ->name('api.company.kds.stations.update');
                    Route::post('stations/{id}/archive', [KdsStationController::class, 'archive'])
                        ->middleware('permission:kds_stations,delete')
                        ->name('api.company.kds.stations.archive');

                    Route::get('stations/{stationId}/tokens', [KdsDeviceTokenController::class, 'index'])
                        ->middleware('permission:kds_stations,read')
                        ->name('api.company.kds.stations.tokens.index');
                    Route::post('stations/{stationId}/tokens', [KdsDeviceTokenController::class, 'store'])
                        ->middleware('permission:kds_stations,create')
                        ->name('api.company.kds.stations.tokens.store');
                    Route::delete('stations/{stationId}/tokens/{tokenId}', [KdsDeviceTokenController::class, 'destroy'])
                        ->middleware('permission:kds_stations,delete')
                        ->name('api.company.kds.stations.tokens.destroy');
                });

                // Caja con pago dividido (#191 Fase 6). Receipts inmutables;
                // refund = receipt nuevo con amount negativo + reference obligatoria.
                Route::get('caja/table-sessions/{id}', [TableCashierController::class, 'show'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.caja.table-sessions.show');
                Route::get('caja/table-sessions/{id}/timeline', [TableCashierController::class, 'timeline'])
                    ->middleware('permission:orders.read,read')
                    ->name('api.caja.table-sessions.timeline');
                Route::post('caja/table-sessions/{id}/pay-partial', [TableCashierController::class, 'payPartial'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.caja.table-sessions.pay-partial');
                Route::post('caja/table-sessions/{id}/pay-all', [TableCashierController::class, 'payAll'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.caja.table-sessions.pay-all');
                Route::post('caja/table-sessions/{id}/refund-item', [TableCashierController::class, 'refundItem'])
                    ->middleware('permission:orders.update,update')
                    ->name('api.caja.table-sessions.refund-item');

                // Admin de mesas físicas (#191 Fase 8). CRUD con soft-archive.
                Route::get('tables', [TableAdminController::class, 'index'])
                    ->middleware('permission:company.update,read')
                    ->name('api.tables.index');
                Route::post('tables', [TableAdminController::class, 'store'])
                    ->middleware('permission:company.update,update')
                    ->name('api.tables.store');
                Route::patch('tables/{id}', [TableAdminController::class, 'update'])
                    ->middleware('permission:company.update,update')
                    ->name('api.tables.update');
                Route::delete('tables/{id}', [TableAdminController::class, 'destroy'])
                    ->middleware('permission:company.update,update')
                    ->name('api.tables.destroy');
                Route::post('tables/{id}/regenerate-qr', [TableAdminController::class, 'regenerateQr'])
                    ->middleware('permission:company.update,update')
                    ->name('api.tables.regenerate-qr');
                Route::post('tables/{id}/restore', [TableAdminController::class, 'restore'])
                    ->middleware('permission:company.update,update')
                    ->name('api.tables.restore');
            }); // fin grupo branch.access (orders)

            // Inventario de insumos (#111). Bitácora append-only — corregir =
            // movimiento `adjustment` opuesto. Stock y costo se mutan SOLO vía
            // los endpoints de movimientos; PATCH de ingrediente toca metadatos.
            // Multi-sede: cada sede tiene su propio inventario aislado.
            // #192: las vistas valuation y history/valuation aceptan
            // `?branch=all` (consolidado por empresa) y `?branch=<uuid>`
            // (otra sede) si el actor tiene `metrics.view_all_branches`.
            // #237 — el módulo entero queda gateado por la capability `inventory`
            // del vertical de la sede activa. Sedes con `inventory:false` en su
            // override del vertical (o vertical que no la habilita) reciben 403
            // BUSINESS_CAPABILITY_DENIED.
            Route::prefix('inventory')->middleware(['branch.access', 'branch.consolidate', 'business.capability:inventory'])->group(function () {
                Route::get('valuation', [IngredientController::class, 'valuation'])
                    ->middleware('permission:inventory.read,read')
                    ->name('api.inventory.valuation');

                Route::get('ingredients', [IngredientController::class, 'index'])
                    ->middleware('permission:inventory.read,read')
                    ->name('api.inventory.ingredients.index');
                Route::post('ingredients', [IngredientController::class, 'store'])
                    ->middleware('permission:inventory.create,create')
                    ->name('api.inventory.ingredients.store');
                Route::get('ingredients/{id}', [IngredientController::class, 'show'])
                    ->middleware('permission:inventory.read,read')
                    ->name('api.inventory.ingredients.show');
                Route::patch('ingredients/{id}', [IngredientController::class, 'update'])
                    ->middleware('permission:inventory.update,update')
                    ->name('api.inventory.ingredients.update');
                Route::delete('ingredients/{id}', [IngredientController::class, 'destroy'])
                    ->middleware('permission:inventory.delete,delete')
                    ->name('api.inventory.ingredients.destroy');
                Route::post('ingredients/{id}/restore', [IngredientController::class, 'restore'])
                    ->middleware('permission:inventory.update,update')
                    ->name('api.inventory.ingredients.restore');

                Route::get('ingredients/{id}/movements', [IngredientMovementController::class, 'index'])
                    ->middleware('permission:inventory.read,read')
                    ->name('api.inventory.movements.index');
                Route::post('ingredients/{id}/movements/entry', [IngredientMovementController::class, 'entry'])
                    ->middleware('permission:inventory.create,create')
                    ->name('api.inventory.movements.entry');
                Route::post('ingredients/{id}/movements/waste', [IngredientMovementController::class, 'waste'])
                    ->middleware('permission:inventory.create,create')
                    ->name('api.inventory.movements.waste');
                Route::post('ingredients/{id}/movements/adjustment', [IngredientMovementController::class, 'adjustment'])
                    ->middleware('permission:inventory.update,update')
                    ->name('api.inventory.movements.adjustment');

                // Transferencias entre bodegas de la misma sede (#120).
                Route::post('transfers', [InventoryTransferController::class, 'store'])
                    ->middleware('permission:inventory.update,update')
                    ->name('api.inventory.transfers.store');

                // Histórico del valor del inventario (snapshots diarios + reconstrucción).
                Route::get('history/valuation', [InventoryHistoryController::class, 'series'])
                    ->middleware('permission:inventory.read,read')
                    ->name('api.inventory.history.valuation');
            });

            // Compras a proveedores (#118). Estados, transiciones y métodos de
            // pago canónicos en `config/purchases.php`. La recepción mueve
            // inventario vía InventoryService; la anulación post-recepción
            // genera una nota crédito + adjustments negativos.
            // Multi-sede: proveedores y POs viven por sede.
            Route::prefix('suppliers')->middleware(['branch.access', 'business.capability:inventory'])->group(function () {
                Route::get('/', [SupplierController::class, 'index'])
                    ->middleware('permission:suppliers.read,read')
                    ->name('api.suppliers.index');
                Route::post('/', [SupplierController::class, 'store'])
                    ->middleware('permission:suppliers.create,create')
                    ->name('api.suppliers.store');
                Route::get('{id}', [SupplierController::class, 'show'])
                    ->middleware('permission:suppliers.read,read')
                    ->name('api.suppliers.show');
                Route::patch('{id}', [SupplierController::class, 'update'])
                    ->middleware('permission:suppliers.update,update')
                    ->name('api.suppliers.update');
                Route::delete('{id}', [SupplierController::class, 'destroy'])
                    ->middleware('permission:suppliers.delete,delete')
                    ->name('api.suppliers.destroy');
                Route::post('{id}/restore', [SupplierController::class, 'restore'])
                    ->middleware('permission:suppliers.update,update')
                    ->name('api.suppliers.restore');
            });

            Route::prefix('purchases')->middleware(['branch.access', 'business.capability:inventory'])->group(function () {
                Route::get('/', [PurchaseOrderController::class, 'index'])
                    ->middleware('permission:purchases.read,read')
                    ->name('api.purchases.index');
                Route::post('/', [PurchaseOrderController::class, 'store'])
                    ->middleware('permission:purchases.create,create')
                    ->name('api.purchases.store');
                Route::get('{id}', [PurchaseOrderController::class, 'show'])
                    ->middleware('permission:purchases.read,read')
                    ->name('api.purchases.show');
                Route::patch('{id}', [PurchaseOrderController::class, 'update'])
                    ->middleware('permission:purchases.update,update')
                    ->name('api.purchases.update');
                Route::post('{id}/submit', [PurchaseOrderController::class, 'submit'])
                    ->middleware('permission:purchases.update,update')
                    ->name('api.purchases.submit');
                Route::post('{id}/receive', [PurchaseOrderController::class, 'receive'])
                    ->middleware('permission:purchases.receive,update')
                    ->name('api.purchases.receive');
                Route::post('{id}/pay', [PurchaseOrderController::class, 'pay'])
                    ->middleware('permission:purchases.pay,update')
                    ->name('api.purchases.pay');
                Route::post('{id}/cancel', [PurchaseOrderController::class, 'cancel'])
                    ->middleware('permission:purchases.update,update')
                    ->name('api.purchases.cancel');
                Route::post('{id}/void', [PurchaseOrderController::class, 'void'])
                    ->middleware('permission:purchases.delete,delete')
                    ->name('api.purchases.void');
                Route::post('{id}/settle-refund', [PurchaseOrderController::class, 'settleRefund'])
                    ->middleware('permission:purchases.pay,update')
                    ->name('api.purchases.settle_refund');

                Route::get('{id}/attachments', [PurchaseAttachmentController::class, 'index'])
                    ->middleware('permission:purchases.read,read')
                    ->name('api.purchases.attachments.index');
                Route::post('{id}/attachments', [PurchaseAttachmentController::class, 'store'])
                    ->middleware('permission:purchases.update,update')
                    ->name('api.purchases.attachments.store');
                Route::get('{id}/attachments/{attachmentId}/url', [PurchaseAttachmentController::class, 'url'])
                    ->middleware('permission:purchases.read,read')
                    ->name('api.purchases.attachments.url');
                Route::get('{id}/attachments/{attachmentId}/download', [PurchaseAttachmentController::class, 'download'])
                    ->middleware('permission:purchases.read,read')
                    ->name('api.purchases.attachments.download');
                Route::delete('{id}/attachments/{attachmentId}', [PurchaseAttachmentController::class, 'destroy'])
                    ->middleware('permission:purchases.update,update')
                    ->name('api.purchases.attachments.destroy');
            });

            // WhatsApp — gestion de la cuenta de la empresa
            Route::prefix('whatsapp')->group(function () {
                Route::get('/', [WhatsappAccountController::class, 'show'])
                    ->middleware('permission:whatsapp.read,read')
                    ->name('api.whatsapp.show');

                // Solicitar / pre-validar codigo de verificacion (cualquier
                // usuario con permiso whatsapp.read; la verificacion final
                // sucede al ejecutar la accion de cambio).
                Route::post('verification/request', [WhatsappVerificationController::class, 'request'])
                    ->middleware('permission:whatsapp.read,read')
                    ->name('api.whatsapp.verification.request');
                Route::post('verification/verify', [WhatsappVerificationController::class, 'verify'])
                    ->middleware('permission:whatsapp.read,read')
                    ->name('api.whatsapp.verification.verify');

                Route::post('embedded-signup-callback', [WhatsappAccountController::class, 'embeddedSignupCallback'])
                    ->middleware('permission:whatsapp.connect,create')
                    ->name('api.whatsapp.embedded-signup-callback');
                Route::post('naas-request', [WhatsappAccountController::class, 'naasRequest'])
                    ->middleware('permission:whatsapp.connect,create')
                    ->name('api.whatsapp.naas-request');

                Route::delete('phone', [WhatsappAccountController::class, 'deletePhone'])
                    ->middleware('permission:whatsapp.swap_phone,delete')
                    ->name('api.whatsapp.phone.delete');
                Route::delete('/', [WhatsappAccountController::class, 'disconnect'])
                    ->middleware('permission:whatsapp.disconnect,delete')
                    ->name('api.whatsapp.disconnect');
            });

            // CRM básico de clientes (#123). Cross-sede: un teléfono = un cliente
            // para toda la empresa, sin importar la sede donde haya pedido. NO
            // requiere `branch.access` por diseño (consolidado).
            // Refactor #235: rutas por contact_id (canónico) en vez de phone,
            // que ya no es único en contacts (familia comparte número).
            Route::prefix('clients')->group(function () {
                Route::get('/', [ClientController::class, 'index'])
                    ->middleware('permission:clients.read,read')
                    ->name('api.clients.index');
                Route::post('/', [ClientController::class, 'store'])
                    ->middleware('permission:clients.create,create')
                    ->name('api.clients.store');
                Route::get('{contact}', [ClientController::class, 'show'])
                    ->middleware('permission:clients.read,read')
                    ->name('api.clients.show');
                Route::post('{contact}/notes', [ClientController::class, 'storeNote'])
                    ->middleware('permission:clients.update,update')
                    ->name('api.clients.notes.store');
                Route::delete('{contact}/notes/{id}', [ClientController::class, 'destroyNote'])
                    ->middleware('permission:clients.delete,delete')
                    ->name('api.clients.notes.destroy');
                Route::post('{contact}/tags', [ClientController::class, 'storeTag'])
                    ->middleware('permission:clients.update,update')
                    ->name('api.clients.tags.store');
                Route::delete('{contact}/tags/{id}', [ClientController::class, 'destroyTag'])
                    ->middleware('permission:clients.delete,delete')
                    ->name('api.clients.tags.destroy');
            });

            // Fidelización con puntos (#122). Cross-sede: una cuenta por
            // (company_nit, client_phone) sin importar la sede activa.
            // #192: el reporte summary acepta `?branch=all` o
            // `?branch=<uuid>` con permiso `metrics.view_all_branches`.
            Route::prefix('loyalty')->group(function () {
                Route::get('accounts', [LoyaltyController::class, 'index'])
                    ->middleware('permission:loyalty.read,read')
                    ->name('api.loyalty.index');
                Route::get('accounts/{phone}', [LoyaltyController::class, 'show'])
                    ->where('phone', '[0-9]+')
                    ->middleware('permission:loyalty.read,read')
                    ->name('api.loyalty.show');
                Route::post('accounts/{phone}/adjust', [LoyaltyController::class, 'adjust'])
                    ->middleware('permission:loyalty.update,update')
                    ->where('phone', '[0-9]+')
                    ->name('api.loyalty.adjust');
                Route::post('accounts/{phone}/redeem', [LoyaltyController::class, 'redeem'])
                    ->middleware('permission:loyalty.update,update')
                    ->where('phone', '[0-9]+')
                    ->name('api.loyalty.redeem');
                Route::get('reports/summary', [LoyaltyReportController::class, 'summary'])
                    ->middleware(['branch.consolidate', 'permission:loyalty.read,read'])
                    ->name('api.loyalty.reports.summary');
            });

            // Alertas accionables (#124). Gate por reports.read (mismo que
            // protege food cost/márgenes) — el contenido del feed expone
            // info financiera indirectamente. Config de reglas requiere
            // company.update (mismo gate que /company/preferences).
            Route::prefix('alerts')->group(function () {
                Route::get('/', [AlertController::class, 'index'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.alerts.index');
                Route::get('summary', [AlertController::class, 'summary'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.alerts.summary');
                Route::post('{id}/dismiss', [AlertController::class, 'dismiss'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.alerts.dismiss');
                Route::post('{id}/action', [AlertController::class, 'action'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.alerts.action');
            });

            Route::prefix('alert-rules')->group(function () {
                Route::get('/', [AlertRuleController::class, 'index'])
                    ->middleware('permission:reports.read,read')
                    ->name('api.alert-rules.index');
                Route::put('{type}', [AlertRuleController::class, 'upsert'])
                    ->middleware('permission:company.update,update')
                    ->name('api.alert-rules.upsert');
            });

            // Conversaciones con clientes (panel del operador)
            Route::get('chats', [ChatController::class, 'index'])
                ->middleware('permission:chats.read,read')
                ->name('api.chats.index');
            Route::get('chats/{id}', [ChatController::class, 'show'])
                ->middleware('permission:chats.read,read')
                ->name('api.chats.show');
            Route::post('chats/{id}/mark-read', [ChatController::class, 'markRead'])
                ->middleware('permission:chats.read,read')
                ->name('api.chats.mark-read');
            Route::get('chats/{id}/client', [ChatController::class, 'clientDetail'])
                ->middleware('permission:chats.read,read')
                ->name('api.chats.client.show');
            Route::post('chats/{id}/messages', [ChatController::class, 'storeMessage'])
                ->middleware('permission:chats.update,update')
                ->name('api.chats.messages.store');
            Route::patch('chats/{id}/bot', [ChatController::class, 'updateBot'])
                ->middleware('permission:chats.update,update')
                ->name('api.chats.bot.update');
            Route::patch('chats/{id}/contact', [ChatController::class, 'updateContact'])
                ->middleware('permission:chats.update,update')
                ->name('api.chats.contact.update');
            // Aislamiento por sede (#192): reasignar chat a otra sede. La
            // autorización es composable (owner OR chats.reassign_branch +
            // acceso a sede destino) y se resuelve dentro del controller —
            // no se aplica middleware permission:* porque el slug no
            // matchea la convención CRUD.
            Route::post('chats/{id}/reassign-branch', [ChatController::class, 'reassignBranch'])
                ->name('api.chats.reassign-branch');

            // Gestión de menú
            // Menús son recurso PER-SEDE (#117): cada branch maneja su carta.
            // El middleware branch.access setea active_branch_id en el request
            // para que el BranchScope global filtre RestaurantMenu automáticamente.
            // Sin esto, GET /menus devolvía menús de TODAS las sedes mezclados.
            Route::prefix('menus')->middleware('branch.access')->group(function () {
                Route::middleware('permission:menu.read,read')->group(function () {
                    Route::get('/', [MenuController::class, 'index'])->name('api.menus.index');
                    Route::get('{id}', [MenuController::class, 'show'])->name('api.menus.show');
                });

                Route::middleware('permission:menu.create,create')->group(function () {
                    Route::post('/', [MenuController::class, 'store'])->name('api.menus.store');
                    Route::post('{id}/duplicate', [MenuController::class, 'duplicate'])->name('api.menus.duplicate');
                    Route::post('{id}/categories', [MenuController::class, 'storeCategory'])->name('api.menus.categories.store');
                    Route::post('{id}/categories/{catId}/items', [MenuController::class, 'storeItem'])->name('api.menus.items.store');
                });

                Route::middleware('permission:menu.update,update')->group(function () {
                    Route::put('{id}', [MenuController::class, 'update'])->name('api.menus.update');
                    Route::patch('{id}/activate', [MenuController::class, 'activate'])->name('api.menus.activate');
                    Route::patch('{id}/deactivate', [MenuController::class, 'deactivate'])->name('api.menus.deactivate');
                    Route::patch('{id}/schedule', [MenuController::class, 'setSchedule'])->name('api.menus.schedule');
                    Route::post('sync-schedule', [MenuController::class, 'syncSchedule'])->name('api.menus.sync-schedule');
                    Route::put('{id}/categories/{catId}', [MenuController::class, 'updateCategory'])->name('api.menus.categories.update');
                    Route::put('{id}/items/{itemId}', [MenuController::class, 'updateItemDirectly'])->name('api.menus.items.update-direct');
                    Route::post('{id}/items/{itemId}/image', [MenuController::class, 'uploadDishImage'])->name('api.menus.items.image');
                    Route::put('{id}/categories/{catId}/items/{itemId}', [MenuController::class, 'updateItem'])->name('api.menus.items.update');
                    Route::patch('{id}/categories/{catId}/items/{itemId}/availability', [MenuController::class, 'updateItemAvailability'])->name('api.menus.items.availability');
                });

                Route::middleware('permission:menu.delete,delete')->group(function () {
                    Route::delete('{id}', [MenuController::class, 'destroy'])->name('api.menus.destroy');
                    Route::delete('{id}/categories/{catId}', [MenuController::class, 'destroyCategory'])->name('api.menus.categories.destroy');
                    Route::delete('{id}/categories/{catId}/items/{itemId}', [MenuController::class, 'destroyItem'])->name('api.menus.items.destroy');
                });

                // Recetas (BOM) por ítem de menú. Piggyback en menu.read/menu.update.
                Route::middleware('permission:menu.read,read')->group(function () {
                    Route::get('{menu}/items/{itemId}/recipe', [RecipeController::class, 'show'])->name('api.menus.items.recipe.show');
                    Route::get('{menu}/items/{itemId}/cost', [RecipeController::class, 'cost'])->name('api.menus.items.cost');
                });
                Route::middleware('permission:menu.update,update')->group(function () {
                    Route::put('{menu}/items/{itemId}/recipe', [RecipeController::class, 'upsert'])->name('api.menus.items.recipe.upsert');
                });
            });
        });
    });

    // PDF de factura — autenticado por firma temporal (sin JWT header requerido)
    Route::get('billing/invoices/{id}/pdf', [BillingController::class, 'servePdf'])
        ->name('api.billing.invoices.pdf')
        ->middleware('signed');

    // Cupon/cart aplicados por OPERADOR (cajero en POS), no por cliente final
    // (#174 P2-3). Por eso usan el JWT de usuario, no cart.jwt — el flujo de
    // comensal aplica cupones via /api/v1/cart/{jwt}. throttle:api alineado
    // con el resto del grupo JWT (240/min por usuario).
    Route::middleware(['jwt', 'throttle:api', 'company.access', 'company.verified', 'company.not_blocked'])->group(function () {
        Route::get('coupons/{code}/validate', [CouponValidationController::class, 'validate'])->name('api.coupons.validate');
        Route::post('cart/apply-coupon', [CartCouponController::class, 'apply'])->name('api.cart.apply-coupon');
        // Auto-apply activo en franja horaria (#125 happy hour) — para mostrar badge.
        Route::post('cart/active-auto-apply', [CartCouponController::class, 'activeAutoApply'])->name('api.cart.active-auto-apply');
    });

    // Menú público — sin auth (TC-3.3.1, issue #26). Cualquier visitante puede ver el menú activo de cualquier empresa,
    // sólo ítems disponibles. El controlador no consume datos del JWT.
    Route::get('public/menu/{companyNit}', [MenuController::class, 'showPublic'])->name('api.menus.public');

    // Telemetría pública del QR del menú (issue #95). Append-only en menu_scan_events
    // particionada. Rate-limit y bot-detection en el controller.
    Route::post('public/menu/{nit}/scan', [MenuController::class, 'recordScan'])
        ->where('nit', '[A-Za-z0-9._-]+')
        ->middleware('throttle:menu-scan-public')
        ->name('api.menus.public.scan');

    // Resolución pública de mesa por nit + número (#191). El cliente que
    // entra a /menus/{nit}?table=N consulta aquí para saber si la mesa
    // existe en la sede default y si tiene una sesión grupal activa.
    // Mismo throttle que scan (30/min IP+nit) — el endpoint es read-only
    // y no expone PII.
    Route::get('public/menu/{nit}/table/{tableNumber}', [TableResolveController::class, 'show'])
        ->where(['nit' => '[A-Za-z0-9._-]+', 'tableNumber' => '\d+'])
        ->middleware('throttle:menu-scan-public')
        ->name('api.menus.public.table.resolve');

    // Mesa con QR (#191) — flujo público sin auth, identidad por cookie firmada
    // `tdt_*`. Migrado del stack web a la API REST cuando el frontend pasó a
    // SPA standalone: el QR escaneado abre la página SPA `/t/{qr}` y ésta
    // hidrata su contexto contra estos endpoints.
    //
    // Throttle dual (IP + QR token) vía el limiter `table-public` definido en
    // AppServiceProvider. La regex de qr_token acepta alfanumérico
    // (Str::random/40). `table.guest` resuelve el comensal desde la cookie en
    // los endpoints que operan sobre el carrito.
    Route::prefix('public/table/{qr_token}')
        ->middleware('throttle:table-public')
        ->where(['qr_token' => '[A-Za-z0-9]+'])
        ->group(function () {
            // Contexto de la pantalla de unión (hidrata `pages/table/join.tsx`).
            Route::get('/', [TableJoinController::class, 'show'])
                ->name('api.public.table.join');

            // Crea/une comensal, setea cookie `tdt_*`, devuelve contexto del menú.
            Route::post('join', [TableJoinController::class, 'store'])
                ->name('api.public.table.join.store');

            // Autocompletar nombre por celular (UX del join). Devuelve { name|null }.
            Route::get('contact-lookup', [TableJoinController::class, 'lookupContact'])
                ->name('api.public.table.contact_lookup');

            // Endpoints que requieren comensal identificado por la cookie `tdt_*`.
            Route::middleware('table.guest')->group(function () {
                // Contexto del menú (hidrata `pages/table/menu.tsx`).
                Route::get('menu', TableMenuController::class)
                    ->name('api.public.table.menu');

                // Carrito del comensal (#191 Fase 3). El frontend los consume
                // con fetch + polling cada 5s.
                Route::get('state', [TableOrderController::class, 'state'])
                    ->name('api.public.table.state');

                Route::post('items', [TableOrderController::class, 'addItem'])
                    ->name('api.public.table.items.add');

                Route::patch('items/{item}', [TableOrderController::class, 'updateItem'])
                    ->name('api.public.table.items.update');

                Route::delete('items/{item}', [TableOrderController::class, 'cancelItem'])
                    ->name('api.public.table.items.cancel');

                Route::post('submit', [TableOrderController::class, 'submitBatch'])
                    ->name('api.public.table.submit');

                Route::post('notes', [TableOrderController::class, 'addNote'])
                    ->name('api.public.table.notes.add');
            });
        });

    // Carrito publico — autenticado solo por el JWT de carrito (CartJwtService).
    // El front lo invoca al abrir la URL pedidos.flexyflow.co/{jwt}.
    Route::post('cart/migrate-jwt/{jwt}', [CartController::class, 'migrateJwt'])
        ->where('jwt', '[A-Za-z0-9._-]+')
        ->name('api.cart.migrate-jwt');
    Route::get('cart/{jwt}', [CartController::class, 'show'])
        ->where('jwt', '[A-Za-z0-9._-]+')
        ->name('api.cart.show');

    // Fidelización pública (#122) — el cliente consulta su saldo y canjea
    // desde /menus/{nit}. POST + rate-limit estricto para no exponer phones.
    // 404 cuando el programa está deshabilitado para la empresa (no revela
    // si el phone existe).
    Route::middleware('throttle:loyalty-public')->group(function () {
        Route::post('public/loyalty/{nit}/lookup', [PublicLoyaltyController::class, 'lookup'])
            ->where('nit', '[A-Za-z0-9._-]+')
            ->name('api.public.loyalty.lookup');
        Route::post('public/loyalty/{nit}/redeem', [PublicLoyaltyController::class, 'redeem'])
            ->where('nit', '[A-Za-z0-9._-]+')
            ->name('api.public.loyalty.redeem');
    });

    // #115 — Kitchen Display System por estación (modo device-token).
    // Las tabletas físicas de cocina autentican con `Authorization: Bearer
    // <token>` o cookie `kds_device_token`. Sin JWT — el middleware
    // `kds.device` resuelve company/branch/station desde el token e inyecta
    // los attributes que normalmente pone el stack JWT. Rate limit dedicado
    // `kds-device` (60/min per token) en lugar del global `api`.
    Route::prefix('kds/{stationSlug}')
        ->middleware(['kds.device', 'throttle:kds-device'])
        ->where(['stationSlug' => '[a-z0-9_-]+'])
        ->group(function () {
            Route::get('tickets', [KdsController::class, 'indexForStation'])
                ->name('api.kds.station.tickets.index');
            Route::patch('items/{itemId}/mark-in-kitchen', [KdsController::class, 'markInKitchenForStation'])
                ->name('api.kds.station.items.mark-in-kitchen');
            Route::patch('items/{itemId}/mark-ready', [KdsController::class, 'markReadyForStation'])
                ->name('api.kds.station.items.mark-ready');
            Route::patch('items/{itemId}/mark-served', [KdsController::class, 'markServedForStation'])
                ->name('api.kds.station.items.mark-served');
        });
});

// Endpoint externo para bot — fuera del prefijo /v1 según contrato API
Route::prefix('external')->middleware('bot.jwt')->group(function () {
    Route::get('hours/status', [ExternalHoursStatusController::class, 'show'])
        ->name('api.external.hours.status');

    // El bot solicita intervencion humana en una conversacion. company_nit
    // se obtiene del JWT de bot, nunca del body, para impedir cross-company.
    Route::post('chats/handoff', [ExternalChatHandoffController::class, 'store'])
        ->name('api.external.chats.handoff');

    // Cache local de conversaciones para reducir llamadas a la API de Meta:
    // el bot empuja cada mensaje (POST) y lee deltas desde BD (GET).
    Route::post('chats/messages', [ExternalChatMessageController::class, 'store'])
        ->name('api.external.chats.messages.store');
    Route::get('chats/messages', [ExternalChatMessageController::class, 'index'])
        ->name('api.external.chats.messages.index');

    // Fidelización para el bot WhatsApp (#122). El bot (n8n) consume estos
    // endpoints al detectar intents `/puntos` y `/canjear` en el chat. El
    // company_nit viene del JWT de bot — nunca del body.
    Route::post('loyalty/lookup', [ExternalLoyaltyController::class, 'lookup'])
        ->name('api.external.loyalty.lookup');
    Route::post('loyalty/redeem', [ExternalLoyaltyController::class, 'redeem'])
        ->name('api.external.loyalty.redeem');
});
