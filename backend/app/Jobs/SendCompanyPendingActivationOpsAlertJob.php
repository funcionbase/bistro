<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyPendingActivationOpsAlert;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Alerta interna: empresa nueva pendiente de aprobación.
 *
 * Job orquestador del envío al buzón ops (`config('mail.ops_alert_address')`,
 * por default `cristian@flexyflow.co`). Idempotencia N-instance:
 *
 *   1. `SELECT ... FOR UPDATE SKIP LOCKED` del driver `database` — un solo
 *      worker procesa cada fila de `jobs`.
 *   2. `ShouldBeUnique` con `uniqueId="ops_alert:{company_nit}"` y
 *      `uniqueFor=86400` — bloquea encolado duplicado por 24 h vía cache
 *      store `database`.
 *   3. `companies.ops_alert_sent_at` consultada con `lockForUpdate` antes de
 *      enviar y actualizada al terminar OK.
 *   4. `after_commit: true` global en `config/queue.php` — el job no se
 *      encola si la transacción del enrollment revierte.
 */
class SendCompanyPendingActivationOpsAlertJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Vida del lock de unicidad — 24 h.
     */
    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $companyNit,
        public readonly string $ownerUserId,
    ) {
        $this->onQueue('notifications');
    }

    public function uniqueId(): string
    {
        return "ops_alert:{$this->companyNit}";
    }

    public function handle(AuditService $audit): void
    {
        $opsAddress = config('mail.ops_alert_address');
        if (empty($opsAddress)) {
            Log::warning('SendCompanyPendingActivationOpsAlertJob: MAIL_OPS_ALERT_ADDRESS no configurado, omitiendo', [
                'company_nit' => $this->companyNit,
            ]);

            return;
        }

        $owner = User::query()->find($this->ownerUserId);
        if (! $owner instanceof User) {
            Log::warning('SendCompanyPendingActivationOpsAlertJob: propietario no encontrado', [
                'company_nit' => $this->companyNit,
                'owner_user_id' => $this->ownerUserId,
            ]);

            return;
        }

        DB::transaction(function () use ($owner, $opsAddress, $audit) {
            // Company PK ahora es id uuid; lookup explícito por nit (UNIQUE).
            $company = Company::query()
                ->where('nit', $this->companyNit)
                ->lockForUpdate()
                ->first();

            if (! $company instanceof Company) {
                Log::warning('SendCompanyPendingActivationOpsAlertJob: empresa no encontrada', [
                    'company_nit' => $this->companyNit,
                ]);

                return;
            }

            if ($company->ops_alert_sent_at !== null) {
                Log::info('SendCompanyPendingActivationOpsAlertJob: alerta ya enviada, omitiendo', [
                    'company_nit' => $this->companyNit,
                    'sent_at' => $company->ops_alert_sent_at->toIso8601String(),
                ]);

                return;
            }

            // At-most-once: marcar ANTES del send (dentro del lockForUpdate)
            // para no disparar 3 copias por reintentos automáticos si el
            // SMTP/SES falla a mitad. Si el envío falla, queda como enviado;
            // el operador re-encola manualmente.
            $company->forceFill(['ops_alert_sent_at' => now()])->save();

            Notification::route('mail', $opsAddress)
                ->notify(new CompanyPendingActivationOpsAlert($company, $owner));

            $audit->log(
                'enrollment.ops_alert_sent',
                user: $owner,
                auditable: $company,
                data: [
                    'company_nit' => $company->nit,
                    'ops_address' => $opsAddress,
                ],
            );
        });
    }

    public function failed(Throwable $e): void
    {
        $audit = app(AuditService::class);
        $owner = User::query()->find($this->ownerUserId);
        $company = Company::query()->where('nit', $this->companyNit)->first();

        $audit->log(
            'enrollment.ops_alert_failed',
            user: $owner,
            auditable: $company,
            data: [
                'company_nit' => $this->companyNit,
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ],
        );
    }
}
