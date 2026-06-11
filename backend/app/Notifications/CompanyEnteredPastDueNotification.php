<?php

namespace App\Notifications;

use App\Models\Company;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparada cuando companies.status pasa de active → past_due (#175).
 */
class CompanyEnteredPastDueNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Company $company) {}

    public function idempotencyKey(): string
    {
        // Incluye past_due_started_at (con hora, iso8601) para que si la empresa
        // sale y vuelve a entrar a past_due — incluso el mismo dia — la nueva
        // entrada genere envio. Consistente con CompanyPaymentBlocked.
        $entry = optional($this->company->past_due_started_at)->toIso8601String() ?? 'unknown';

        return "company:{$this->company->nit}:past_due:{$entry}";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'company_nit' => $this->company->nit,
            'past_due_started_at' => optional($this->company->past_due_started_at)->toDateString(),
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
        return (new MailMessage)
            ->subject('Tu cuenta entró en mora — período de gracia activo')
            ->markdown('emails.billing.entered-past-due', [
                'name' => $notifiable->name,
                'blockDate' => $this->company->expected_block_at?->format('d/m/Y') ?? 'fecha por confirmar',
                'panelUrl' => route('billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'expected_block_at' => $this->company->expected_block_at?->toDateString(),
        ];
    }
}
