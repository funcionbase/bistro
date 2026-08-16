<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\Subscription;
use App\Notifications\Contracts\BillingNotificationContract;
use App\Services\BillingPlanPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notificacion disparada cuando una empresa pasa de
 * `pending_activation` a `active` (aprobacion del registro por el equipo
 * interno via `companies:approve`).
 *
 * Receptores: owners + admins activos de la empresa, resueltos por
 * Company::usersToNotifyForBilling().
 *
 * El cuerpo incluye nombre del plan, precio formateado, lista de capacidades
 * y noticia tributaria, leidos desde el snapshot inmutable de la Subscription
 * via BillingPlanPresenter.
 */
class CompanyRegistrationApprovedNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Company $company,
        private readonly Subscription $subscription,
    ) {}

    public function idempotencyKey(): string
    {
        return "company:{$this->company->nit}:activated:{$this->subscription->id}";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'company_nit' => $this->company->nit,
            'subscription_id' => $this->subscription->id,
            'plan_name_snapshot' => $this->subscription->plan_name_snapshot,
            'plan_price_snapshot' => $this->subscription->plan_price_snapshot,
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
        $companyName = $this->company->commercial_name;

        return (new MailMessage)
            ->subject("¡{$companyName} ya está activa en bistro!")
            ->markdown('emails.billing.registration-approved', [
                'companyName' => $companyName,
                'name' => $notifiable->name,
                'plan' => BillingPlanPresenter::forSubscription($this->subscription),
                'trialEndsAt' => $this->company->paid_billing_starts_at?->format('d/m/Y'),
                'panelUrl' => route('billing'),
                'supportEmail' => config('mail.reply_to.address', 'hello@funcionbase.com'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'company_nit' => $this->company->nit,
            'subscription_id' => $this->subscription->id,
            'activated_at' => now()->toIso8601String(),
        ];
    }
}
