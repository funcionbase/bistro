<?php

namespace App\Notifications;

use App\Models\Company;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparada cuando companies.status pasa de past_due → suspended.
 */
class CompanyPaymentBlockedNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Company $company) {}

    public function idempotencyKey(): string
    {
        $blockedAt = optional($this->company->payment_blocked_at)->toIso8601String() ?? 'unknown';

        return "company:{$this->company->nit}:suspended:{$blockedAt}";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'company_nit' => $this->company->nit,
            'payment_blocked_at' => optional($this->company->payment_blocked_at)->toIso8601String(),
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
            ->subject('Tu cuenta de bistro fue suspendida por mora')
            ->markdown('emails.billing.payment-blocked', [
                'name' => $notifiable->name,
                'panelUrl' => route('billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'payment_blocked_at' => $this->company->payment_blocked_at?->toIso8601String(),
        ];
    }
}
