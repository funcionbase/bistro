<?php

namespace App\Notifications;

use App\Models\Company;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparada cuando companies.status pasa de past_due|suspended → active (#175).
 */
class CompanyReactivatedNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Company $company) {}

    public function idempotencyKey(): string
    {
        // Incluye last_paid_at (con hora, iso8601) para que reactivaciones
        // sucesivas (paga, vuelve a entrar a past_due, paga otra vez) generen
        // filas distintas. Consistente con CompanyPaymentBlocked / EnteredPastDue.
        $lastPaidAt = optional($this->company->last_paid_at)->toIso8601String() ?? 'unknown';

        return "company:{$this->company->nit}:reactivated:{$lastPaidAt}";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'company_nit' => $this->company->nit,
            'last_paid_at' => optional($this->company->last_paid_at)->toDateString(),
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
            ->subject('¡Tu cuenta de bistro fue reactivada!')
            ->markdown('emails.billing.reactivated', [
                'name' => $notifiable->name,
                'companyName' => $this->company->commercial_name,
                'dashboardUrl' => route('dashboard'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'reactivated_at' => now()->toIso8601String(),
        ];
    }
}
