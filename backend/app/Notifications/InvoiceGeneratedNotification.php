<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Contracts\BillingNotificationContract;
use App\Services\BillingPlanPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Disparada por BillingService::generateMonthlyInvoices cuando se crea una
 * invoice mensual nueva. Receptores: owners + admins activos via
 * Company::usersToNotifyForBilling().
 *
 * Incluye bloque "Tu plan" leyendo el snapshot de la subscription via
 * BillingPlanPresenter, y la instruccion explicita de subir comprobante de
 * pago manual en /billing (transferencia BREB / cuenta bistro).
 */
class InvoiceGeneratedNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice) {}

    public function idempotencyKey(): string
    {
        return "invoice:{$this->invoice->id}:generated";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'company_nit' => $this->invoice->company_nit,
            'subscription_id' => $this->invoice->subscription_id,
            'amount' => (string) $this->invoice->amount,
            'currency' => $this->invoice->currency,
            'period_from' => $this->invoice->period_from?->toDateString(),
            'period_to' => $this->invoice->period_to?->toDateString(),
        ];
    }

    public function companyNit(): string
    {
        return (string) $this->invoice->company_nit;
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['deduped_mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invoice = $this->invoice->loadMissing(['subscription.plan']);
        $subscription = $invoice->subscription;
        $period = $invoice->period_from->isoFormat('MMMM YYYY');

        $plan = $subscription !== null
            ? BillingPlanPresenter::forSubscription($subscription)
            : ['name' => 'Plan'];

        return (new MailMessage)
            ->subject("Nueva factura — {$plan['name']} — {$period}")
            ->markdown('emails.billing.invoice-generated', [
                'name' => $notifiable->name,
                'plan' => $plan,
                'period' => $period,
                'periodFrom' => $invoice->period_from->format('d/m/Y'),
                'periodTo' => $invoice->period_to->format('d/m/Y'),
                'amount' => number_format((float) $invoice->amount, 0, ',', '.'),
                'currency' => $invoice->currency,
                'dueDate' => $invoice->due_date->format('d/m/Y'),
                'panelUrl' => route('billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['invoice_id' => $this->invoice->id];
    }
}
