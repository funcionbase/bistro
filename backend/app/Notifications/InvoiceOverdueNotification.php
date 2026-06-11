<?php

namespace App\Notifications;

use App\Models\Invoice;
use App\Notifications\Contracts\BillingNotificationContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceOverdueNotification extends Notification implements BillingNotificationContract, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice) {}

    public function idempotencyKey(): string
    {
        return "invoice:{$this->invoice->id}:overdue";
    }

    /** @return array<string, mixed> */
    public function dispatchMetadata(): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'company_nit' => $this->invoice->company_nit,
            'due_date' => optional($this->invoice->due_date)->toDateString(),
            'amount' => (string) $this->invoice->amount,
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
        $planName = $invoice->subscription?->plan_name_snapshot
            ?? $invoice->subscription?->plan?->name
            ?? 'Plan';

        return (new MailMessage)
            ->subject('Factura vencida — pago pendiente')
            ->markdown('emails.billing.invoice-overdue', [
                'name' => $notifiable->name,
                'planName' => $planName,
                'amount' => number_format((float) $invoice->amount, 0, ',', '.'),
                'currency' => $invoice->currency,
                'dueDate' => $invoice->due_date->format('d/m/Y'),
                'panelUrl' => route('billing'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
        ];
    }
}
