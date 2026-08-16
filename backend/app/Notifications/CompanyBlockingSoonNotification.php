<?php

namespace App\Notifications;

use App\Models\Company;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Recordatorio enviado cuando faltan ≤7 días para que past_due pase a suspended.
 * Se dispara desde recalculateCompanyStatus (idempotencia: la guardia se hace
 * en el llamador para no enviar dos veces el mismo día).
 */
class CompanyBlockingSoonNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Company $company,
        private readonly int $daysLeft
    ) {}

    public function idempotencyKey(): string
    {
        // 1 envio por dia — el evento se dispara desde el cron, asi que el
        // key incluye la fecha para que retries del mismo dia colapsen.
        return "company:{$this->company->nit}:blocking_soon:".now()->toDateString();
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'company_nit' => $this->company->nit,
            'days_left' => $this->daysLeft,
            'expected_block_at' => optional($this->company->expected_block_at)->toDateString(),
        ];
    }

    public function companyNit(): string
    {
        return (string) $this->company->nit;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['deduped_mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $unit = $this->daysLeft === 1 ? 'día' : 'días';

        return (new MailMessage)
            ->subject("Tu cuenta será suspendida en {$this->daysLeft} {$unit}")
            ->markdown('emails.billing.blocking-soon', [
                'name' => $notifiable->name,
                'daysLeft' => $this->daysLeft,
                'unit' => $unit,
                'blockDate' => $this->company->expected_block_at?->format('d/m/Y') ?? 'esta semana',
                'panelUrl' => route('billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'expected_block_at' => $this->company->expected_block_at?->toDateString(),
            'days_left' => $this->daysLeft,
        ];
    }
}
