<?php

namespace App\Notifications;

use App\Models\PaymentProof;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Email a ops (BILLING_OPS_EMAIL) cuando un cliente sube un comprobante de pago
 * desde la pantalla de facturación. El email incluye URL firmada al S3 (TTL 24h)
 * para que ops pueda descargar y validar el comprobante manualmente.
 */
class PaymentProofSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly PaymentProof $proof) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $disk = config('billing.payment_proof_disk', 's3_documents');
        $signedUrl = Storage::disk($disk)->temporaryUrl(
            $this->proof->file_path,
            now()->addDay()
        );

        $sizeKb = number_format($this->proof->size_bytes / 1024, 1);
        $invoiceList = empty($this->proof->invoice_ids)
            ? 'no especificadas'
            : implode(', ', $this->proof->invoice_ids);

        return (new MailMessage)
            ->subject("Comprobante de pago recibido — NIT {$this->proof->company_nit}")
            ->line("**NIT:** {$this->proof->company_nit}")
            ->line("**Facturas referenciadas:** {$invoiceList}")
            ->line("**Archivo:** {$this->proof->original_name} ({$this->proof->mime}, {$sizeKb} KB)")
            ->line("**Subido por (user_id):** {$this->proof->uploaded_by_user_id}")
            ->action('Descargar comprobante', $signedUrl)
            ->line('Tras validar el pago, marca las invoices correspondientes como `paid` y la empresa volverá a `active` automáticamente en el siguiente run del cron.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_proof_id' => $this->proof->id,
            'company_nit' => $this->proof->company_nit,
        ];
    }
}
