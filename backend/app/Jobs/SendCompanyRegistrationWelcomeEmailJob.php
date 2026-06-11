<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyRegistrationPendingNotification;
use App\Services\AuditService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Correo transaccional de "registro exitoso".
 *
 * Job orquestador del envío. Garantiza idempotencia N-instance safe
 * mediante 4 capas:
 *
 *   1. `SELECT ... FOR UPDATE SKIP LOCKED` del driver `database` de la cola
 *      — un solo worker procesa cada fila de `jobs`.
 *   2. `ShouldBeUnique` con `uniqueId="welcome_email:{user_id}:{company_nit}"`
 *      y `uniqueFor=86400` — bloquea encolado duplicado por 24 h vía cache
 *      store `database` (compartido entre EC2 vía PostgreSQL).
 *   3. `companies.welcome_email_sent_at` consultada antes de enviar dentro de
 *      `DB::transaction` con `lockForUpdate`, actualizada al terminar OK.
 *   4. `after_commit: true` global en `config/queue.php` — el job no se encola
 *      si la transacción del enrollment revierte.
 *
 * Sin ShouldBeUnique, dos requests concurrentes del frontend (doble-tap) o un
 * reencolado manual desde `failed_jobs` podrían disparar dos correos al mismo
 * usuario. Con las 4 capas, el envío es exactamente una vez por `(user_id,
 * company_nit)`.
 *
 * Errores: cualquier excepción en `handle()` deja el job en `failed_jobs` tras
 * agotar los reintentos. `failed()` loggea `enrollment.welcome_email_failed`
 * para que el operador investigue sin afectar el registro de la empresa (que
 * ya commiteó en `CompanyEnrollmentController::store()`).
 */
class SendCompanyRegistrationWelcomeEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    /**
     * Vida del lock de unicidad — 24 h. Cubre el peor caso de fallo prolongado
     * de SES + reintentos sin permitir un re-envío legítimo del mismo correo.
     */
    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $userId,
        public readonly string $companyNit,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Identidad para el lock de unicidad (capa 2). El cache store es
     * `database` (config/cache.php), compartido entre todas las EC2 del ASG.
     */
    public function uniqueId(): string
    {
        return "welcome_email:{$this->userId}:{$this->companyNit}";
    }

    public function handle(AuditService $audit): void
    {
        $user = User::query()->find($this->userId);
        if (! $user instanceof User) {
            Log::warning('SendCompanyRegistrationWelcomeEmailJob: usuario no encontrado', [
                'user_id' => $this->userId,
                'company_nit' => $this->companyNit,
            ]);

            return;
        }

        DB::transaction(function () use ($user, $audit) {
            // Company PK ahora es id uuid; lookup explícito por nit (UNIQUE).
            $company = Company::query()
                ->where('nit', $this->companyNit)
                ->lockForUpdate()
                ->first();

            if (! $company instanceof Company) {
                Log::warning('SendCompanyRegistrationWelcomeEmailJob: empresa no encontrada', [
                    'user_id' => $this->userId,
                    'company_nit' => $this->companyNit,
                ]);

                return;
            }

            if ($company->welcome_email_sent_at !== null) {
                Log::info('SendCompanyRegistrationWelcomeEmailJob: correo ya enviado, omitiendo', [
                    'user_id' => $this->userId,
                    'company_nit' => $this->companyNit,
                    'sent_at' => $company->welcome_email_sent_at->toIso8601String(),
                ]);

                return;
            }

            // At-most-once: marcar ANTES del send (dentro del lockForUpdate)
            // para evitar duplicados si el worker es reiniciado a mitad del
            // SMTP o el reintento automático vuelve a entrar. Si el SMTP falla,
            // el correo queda como enviado y el operador puede reenviar
            // manualmente — preferimos perder un email a mandar 3 copias.
            $company->forceFill(['welcome_email_sent_at' => now()])->save();

            Notification::sendNow($user, new CompanyRegistrationPendingNotification($company));

            $audit->log(
                'enrollment.welcome_email_sent',
                user: $user,
                auditable: $company,
                data: [
                    'company_nit' => $company->nit,
                    'notifiable_route' => $user->email,
                ],
            );
        });
    }

    public function failed(Throwable $e): void
    {
        $audit = app(AuditService::class);
        $user = User::query()->find($this->userId);
        $company = Company::query()->where('nit', $this->companyNit)->first();

        $audit->log(
            'enrollment.welcome_email_failed',
            user: $user,
            auditable: $company,
            data: [
                'company_nit' => $this->companyNit,
                'reason' => $e->getMessage(),
                'exception' => $e::class,
            ],
        );
    }
}
