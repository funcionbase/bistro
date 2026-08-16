<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CompanyFactory;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Empresa restaurante.
 *
 * PK interna: `id` UUID (HasUuids trait). El UUID es lo que devuelve `getKey()`
 * y lo que termina en `audit_logs.auditable_id` cuando el modelo se audita
 * vía AuditService::log().
 *
 * Identificador externo: `nit` (string, UNIQUE, inmutable). Es el que usan:
 *   - Las 31+ tablas hijas vía FK `company_nit` → `companies.nit` (FK contra
 *     UNIQUE no-PK, soportado por PostgreSQL).
 *   - El JWT (`active_company_nit`) y todo el flujo de auth/bootstrap.
 *   - Los QR públicos (`/menus/{nit}`, `/loyalty/{nit}`), facturas DIAN,
 *     comprobantes en S3 y cualquier sistema externo.
 * Por eso `nit` no es PK: queremos un id interno desacoplado, pero el NIT
 * sigue siendo el identificador estable de cara afuera.
 *
 * Estado: pending_activation | active | past_due | suspended | rejected | inactive.
 * La transición por atraso de pago la maneja BillingService::recalculateCompanyStatus()
 * (idempotente, derivada de invoices + companies.created_at + trial_days).
 *
 * NIT inmutable: una vez creada la empresa, su NIT NO se puede modificar.
 * Defensa tripleta: trigger BD (migración 0001_01_01_000100), mutator
 * `setNitAttribute` en este modelo, y UpdateCompanyRequest que no expone
 * `nit` en sus rules.
 *
 * @property string $id — UUID v7 (PK interna)
 * @property string $nit — NIT del restaurante (UNIQUE, identificador externo)
 * @property string $commercial_name
 * @property string $status — pending_activation | active | past_due | suspended | rejected | inactive
 * @property CarbonImmutable|null $past_due_started_at — momento en que entró en past_due
 * @property CarbonImmutable|null $expected_block_at — fecha cache para countdown
 * @property CarbonImmutable|null $payment_blocked_at — momento de la suspensión
 * @property CarbonImmutable|null $paid_billing_starts_at — primer día facturable
 * @property CarbonImmutable|null $last_paid_at — último invoice payment exitoso
 */
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUuids;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        // `id` lo genera HasUuids automáticamente al guardar; no se llena
        // desde requests (cualquier intento se ignora porque está fuera de
        // fillable).
        'nit',
        'commercial_name',
        'legal_name',
        'logo_path',
        'qr_code_path',
        'breb_key',
        'bank_id',
        'account_number',
        'account_type',
        'status',
        // Configuración tributaria parametrizable por empresa (CO).
        'tax_regime',
        'default_tax_rate',
        'default_tax_label',
        'tax_included_in_price',
        // Cronología del atraso de pago. No se setean directamente
        // desde requests del cliente: BillingService::recalculateCompanyStatus
        // es la única fuente que muta estos campos.
        'past_due_started_at',
        'expected_block_at',
        'payment_blocked_at',
        'last_paid_at',
        // Trial extendido. NULL = la empresa usa el trial global
        // (`BILLING_TRIAL_DAYS`). Valor seteado = no se generan invoices
        // para periodos anteriores a esa fecha y no se transiciona a
        // past_due hasta que llegue.
        'paid_billing_starts_at',
        // Marcas de envío de los correos transaccionales del enrolamiento.
        // 3ª capa de idempotencia tras lock de fila + ShouldBeUnique.
        // Las manejan SendCompanyRegistrationWelcomeEmailJob (propietario)
        // y SendCompanyPendingActivationOpsAlertJob (equipo interno); nunca
        // se setean desde el cliente.
        'welcome_email_sent_at',
        'ops_alert_sent_at',
        // Marcas de notificaciones billing (at-most-once cross-instance EC2).
        // Gestionadas por BillingService::notifyOnce(). Nunca desde cliente.
        'blocking_soon_notified_on',
        'past_due_notified_at',
        'suspended_notified_at',
        'reactivated_notified_at',
        // Marker de envio del correo de aprobacion del registro.
        // Setea BillingService::activateCompany() tras disparar la notif.
        'activation_notified_at',
        // Perfil fiscal DIAN. Mínimos del XML UBL 2.1 Colombia.
        // El owner los completa en Configuración → Facturación DIAN antes
        // de poder emitir el primer documento.
        'dv',
        'legal_representative_name',
        'legal_representative_doc_type',
        'legal_representative_doc_number',
        'economic_activity_code',
        'fiscal_responsibilities',
        'tax_obligations',
        'municipality_dane_code',
        'billing_email',
        'billing_phone',
        'physical_address',
        'country_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'default_tax_rate' => 'decimal:2',
            'tax_included_in_price' => 'boolean',
            'past_due_started_at' => 'immutable_datetime',
            'expected_block_at' => 'immutable_date',
            'payment_blocked_at' => 'immutable_datetime',
            'last_paid_at' => 'immutable_date',
            'paid_billing_starts_at' => 'immutable_date',
            'welcome_email_sent_at' => 'immutable_datetime',
            'ops_alert_sent_at' => 'immutable_datetime',
            'blocking_soon_notified_on' => 'immutable_date',
            'past_due_notified_at' => 'immutable_datetime',
            'suspended_notified_at' => 'immutable_datetime',
            'reactivated_notified_at' => 'immutable_datetime',
            'activation_notified_at' => 'immutable_datetime',
            'fiscal_responsibilities' => 'array',
            'tax_obligations' => 'array',
        ];
    }

    /** @return HasOne<DianProviderConfig, $this> */
    public function activeDianProviderConfig(): HasOne
    {
        return $this->hasOne(DianProviderConfig::class, 'company_nit', 'nit')->where('is_active', true);
    }

    /** @return HasMany<DianResolution, $this> */
    public function dianResolutions(): HasMany
    {
        return $this->hasMany(DianResolution::class, 'company_nit', 'nit');
    }

    /** @return HasOne<DianDefaultRecipient, $this> */
    public function dianDefaultRecipient(): HasOne
    {
        return $this->hasOne(DianDefaultRecipient::class, 'company_nit', 'nit');
    }

    /** @return HasMany<ElectronicDocument, $this> */
    public function electronicDocuments(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class, 'company_nit', 'nit');
    }

    /**
     * Mutator que protege la inmutabilidad del NIT (defensa #2 — Model).
     *
     * Permite el primer set (creación: `$this->attributes['nit']` aún no existe)
     * pero rechaza cualquier asignación posterior con un valor distinto, ya sea
     * vía `$company->nit = 'X'`, `$company->update(['nit' => ...])` o
     * `$company->fill(['nit' => ...])`. La excepción es `DomainException` para
     * distinguirla de errores técnicos — el caller debe traducirla a un 422 si
     * está en un controller.
     */
    public function setNitAttribute(string $value): void
    {
        $previous = $this->attributes['nit'] ?? null;

        if ($previous !== null && $previous !== $value) {
            throw new DomainException(
                "companies.nit es inmutable (intento: {$previous} -> {$value}). ".
                'Crear empresa nueva si el NIT es incorrecto.'
            );
        }

        $this->attributes['nit'] = $value;
    }

    /**
     * Relación con el banco asociado.
     */
    public function bank()
    {
        return $this->belongsTo(Bank::class);
    }

    public function isPendingActivation(): bool
    {
        return $this->status === 'pending_activation';
    }

    /**
     * ¿La empresa puede servir al público? Devuelve false cuando el
     * status está en `config('companies.fully_blocked')` (hoy: `suspended`).
     *
     * Distinto de `isOperational()` que devuelve true para past_due/suspended
     * (porque pasan el gate `EnsureCompanyVerified`). Este es un check
     * estricto: el comensal NO debe poder consumir el menú/cart/loyalty/QR
     * de una empresa suspended. Las respuestas son indistinguibles de "no
     * encontrado" / "no disponible" para no revelar motivo comercial.
     */
    public function canServePublic(): bool
    {
        $blocked = (array) config('companies.fully_blocked', ['suspended']);

        return ! in_array($this->status, $blocked, true);
    }

    /** @return HasMany<CompanyUser, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(CompanyUser::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CompanyUser, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(CompanyUser::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CompanyInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'company_nit', 'nit');
    }

    /** @return HasMany<RestaurantMenu, $this> */
    public function menus(): HasMany
    {
        return $this->hasMany(RestaurantMenu::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CompanySetting, $this> */
    public function settings(): HasMany
    {
        return $this->hasMany(CompanySetting::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'company_nit', 'nit');
    }

    /** @return HasMany<BusinessHour, $this> */
    public function businessHours(): HasMany
    {
        return $this->hasMany(BusinessHour::class, 'company_nit', 'nit');
    }

    /** @return HasMany<BusinessHourException, $this> */
    public function businessHourExceptions(): HasMany
    {
        return $this->hasMany(BusinessHourException::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'company_nit', 'nit');
    }

    /** @return HasOne<Subscription, $this> */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'company_nit', 'nit')->where('status', 'active');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'company_nit', 'nit');
    }

    /** @return HasMany<CompanyPromoCode, $this> */
    public function companyPromoCodes(): HasMany
    {
        return $this->hasMany(CompanyPromoCode::class, 'company_nit', 'nit');
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * El gate `EnsureCompanyVerified` permite
     * operar a empresas en `config('companies.verified')` (hoy: active,
     * past_due, suspended). Se consulta también desde Inertia shared props
     * para que el frontend muestre la pantalla "Cuenta en revisión" cuando
     * aplique.
     */
    public function isOperational(): bool
    {
        return in_array($this->status, config('companies.verified'), true);
    }

    /** @return HasOne<EnrollmentProof, $this> */
    public function enrollmentProof(): HasOne
    {
        return $this->hasOne(EnrollmentProof::class, 'company_nit', 'nit');
    }

    /** @return HasOne<CompanyWhatsappAccount, $this> */
    public function whatsappAccount(): HasOne
    {
        return $this->hasOne(CompanyWhatsappAccount::class, 'company_nit', 'nit');
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class, 'company_nit', 'nit');
    }

    /** @return HasOne<Branch, $this> */
    public function defaultBranch(): HasOne
    {
        return $this->hasOne(Branch::class, 'company_nit', 'nit')
            ->where('is_default', true)
            ->whereNull('archived_at');
    }

    public function hasWhatsappConnected(): bool
    {
        $account = $this->whatsappAccount()->first();

        return $account !== null && $account->isConnected();
    }

    /**
     * Destinatarios canónicos de notificaciones billing de la empresa:
     * propietario(s) + administrador(es), todos con `users.status = 'active'`,
     * deduplicados por `user_id`.
     *
     * Filtro de roles: `company_roles.is_system = true` (defensa contra roles
     * custom con nombre 'Administrador' creados por el cliente) AND
     * `LOWER(name) IN (LOWER(config('roles.role_names.owner')), LOWER(config('roles.role_names.admin')))`.
     * Excluye empleados (también is_system=true).
     *
     * Usado por BillingService y LifecycleManager al disparar:
     *   InvoiceGenerated, InvoiceOverdue, CompanyEnteredPastDue,
     *   CompanyBlockingSoon, CompanyPaymentBlocked, CompanyReactivated,
     *   CompanyRegistrationApproved.
     *
     * No se usa para CompanyRegistrationPending (al momento del enrollment solo
     * existe el propietario) ni para alertas ops (BILLING_OPS_EMAIL).
     *
     * @return Collection<int, User>
     */
    public function usersToNotifyForBilling(): Collection
    {
        $ownerName = strtolower((string) config('roles.role_names.owner', 'Propietario'));
        $adminName = strtolower((string) config('roles.role_names.admin', 'Administrador'));

        return User::query()
            ->where('users.status', 'active')
            ->whereHas('companyUsers', function ($q) use ($ownerName, $adminName): void {
                $q->where('company_users.company_nit', $this->nit)
                    ->where('company_users.status', 'active')
                    ->whereHas('role', function ($r) use ($ownerName, $adminName): void {
                        $r->where('is_system', true)
                            ->whereRaw('LOWER(name) IN (?, ?)', [$ownerName, $adminName]);
                    });
            })
            ->distinct()
            ->get();
    }
}
