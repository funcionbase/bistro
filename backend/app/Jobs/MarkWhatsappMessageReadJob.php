<?php

namespace App\Jobs;

use App\Models\CompanyWhatsappAccount;
use App\Services\Whatsapp\MetaGraphApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Marca un mensaje entrante de WhatsApp como "leido" en Meta. Solo se despacha
 * si la empresa habilito el setting `whatsapp_read_receipts`.
 *
 * Va en cola para no agregar latencia al webhook (Meta acepta 200 inmediato y
 * la marca como leida llega segundos despues, eso es comportamiento esperado).
 *
 * Best-effort: si Meta responde error o el token expira, lo logueamos y no
 * reintentamos — el doble chulito azul es UX, no critico.
 */
class MarkWhatsappMessageReadJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $companyNit,
        public readonly string $metaMessageId,
    ) {}

    public function handle(): void
    {
        $account = CompanyWhatsappAccount::query()
            ->where('company_nit', $this->companyNit)
            ->first();

        if ($account === null || ! $account->isConnected() || empty($account->phone_number_id)) {
            return;
        }

        $token = $account->accessToken();
        if (empty($token)) {
            return;
        }

        $graph = MetaGraphApiClient::forCurrentEnvironment();
        if ($graph === null) {
            return;
        }

        $ok = $graph->markMessageAsRead($account->phone_number_id, $token, $this->metaMessageId);

        if (! $ok) {
            Log::channel('single')->warning('whatsapp.read_receipt.failed', [
                'company_nit' => $this->companyNit,
                'meta_message_id' => $this->metaMessageId,
            ]);
        }
    }
}
