<?php

namespace App\Jobs;

use App\Models\CompanyWhatsappAccount;
use App\Services\Whatsapp\EvolutionClient;
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
        public readonly string $whatsappAccountId,
        public readonly string $metaMessageId,
        // Evolution exige la clave completa del mensaje para marcarlo leído
        // (`{id, fromMe, remoteJid}`), no el id suelto que le bastaba a Meta con
        // el wamid — verificado contra 2.3.7 en F0. Se recibe acá aunque el
        // camino de Meta todavía no lo use, para no volver a cambiar la firma en
        // F2: durante un instance refresh un cambio de firma revienta los jobs
        // que ya están en cola.
        public readonly ?string $clientPhone = null,
    ) {}

    public function handle(): void
    {
        // Multi-canal (F1): el read receipt sale por el canal que originó la
        // conversación, no por "el canal de la empresa" — con canales de sede
        // el token de uno no sirve para el número del otro.
        $account = CompanyWhatsappAccount::query()->find($this->whatsappAccountId);

        if ($account === null || ! $account->isConnected()) {
            return;
        }

        $ok = $account->canSendViaEvolution()
            ? $this->markViaEvolution($account)
            : $this->markViaMeta($account);

        if (! $ok) {
            Log::channel('single')->warning('whatsapp.read_receipt.failed', [
                'whatsapp_account_id' => $this->whatsappAccountId,
                'meta_message_id' => $this->metaMessageId,
            ]);
        }
    }

    private function markViaEvolution(CompanyWhatsappAccount $account): bool
    {
        // Sin el teléfono no se puede armar el `remoteJid` que exige Evolution.
        // Pasa solo con jobs encolados por una versión anterior durante el
        // refresh: se descarta en silencio, el doble chulito es UX, no crítico.
        if ($this->clientPhone === null) {
            return false;
        }

        $result = EvolutionClient::forAccount($account)->markRead(
            (string) $account->evo_instance,
            (string) $account->evoToken(),
            $this->metaMessageId,
            EvolutionClient::toJid($this->clientPhone),
        );

        return (bool) ($result['ok'] ?? false);
    }

    private function markViaMeta(CompanyWhatsappAccount $account): bool
    {
        if (empty($account->phone_number_id)) {
            return false;
        }

        $token = $account->accessToken();
        if (empty($token)) {
            return false;
        }

        $graph = MetaGraphApiClient::forCurrentEnvironment();
        if ($graph === null) {
            return false;
        }

        return $graph->markMessageAsRead($account->phone_number_id, $token, $this->metaMessageId);
    }
}
