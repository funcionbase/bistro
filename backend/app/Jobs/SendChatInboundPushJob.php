<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Aviso de mensaje entrante de WhatsApp a los operadores de la sede (§8.4b punto 1).
 *
 * **Por qué existe**: con Baileys el número sigue vivo en el celular del dueño,
 * así que a él WhatsApp le avisa igual. El problema no es que el mensaje se
 * pierda, es *quién puede atenderlo*: operadores, cajeros y encargados de sede
 * no tienen ese teléfono. Sin este aviso el panel es un espejo pasivo del
 * celular del dueño y se pierden las tres razones por las que existe el módulo
 * — aislamiento por sede, auditoría de quién respondió, y varias personas
 * atendiendo a la vez.
 *
 * El job recibe solo el id del mensaje: la serialización a la cola queda barata
 * y la resolución ocurre en `handle()` con datos frescos.
 *
 * **No se notifica**: lo que mandó el dueño desde su celular (`from_device`) ni
 * lo que sale del panel. Solo mensajes del cliente.
 */
class SendChatInboundPushJob implements ShouldQueue
{
    use Queueable;

    /** @var int */
    public $tries = 3;

    /** @var int */
    public $backoff = 30;

    /**
     * Ventana de silencio por conversación. Un cliente que manda cinco mensajes
     * seguidos —el caso normal cuando dicta un pedido— genera UN aviso, no
     * cinco. Sin esto la notificación se vuelve ruido y el operador la apaga,
     * que es peor que no tenerla.
     */
    private const THROTTLE_SECONDS = 60;

    public function __construct(public string $chatMessageId)
    {
        $this->onQueue('notifications');
    }

    public function handle(WebPushDispatcher $dispatcher): void
    {
        if (! $dispatcher->isConfigured()) {
            return;
        }

        $message = ChatMessage::query()->find($this->chatMessageId);

        if ($message === null || $message->sender !== 'client') {
            return;
        }

        // `withoutBranchScope`: el job corre sin JWT, no hay `active_branch_id`.
        // La sede sale del chat y se usa para FILTRAR destinatarios, que es el
        // uso correcto — acá el scope no puede venir de la request.
        $chat = Chat::withoutBranchScope()->find($message->chat_id);

        if ($chat === null) {
            return;
        }

        // `Cache::add` es atómico sobre el store compartido: con N instancias en
        // el ASG, dos webhooks concurrentes no producen dos avisos.
        if (! Cache::add("chat:push:{$chat->id}", 1, self::THROTTLE_SECONDS)) {
            return;
        }

        $companyNit = (string) $chat->company_nit;

        $subs = PushSubscription::query()
            ->active()
            ->where('company_nit', $companyNit)
            ->with('user')
            ->get();

        if ($subs->isEmpty()) {
            return;
        }

        $payload = [
            'title' => $chat->client_name ?: $chat->client_phone,
            'body' => $this->preview($message),
            // Deep-link ya soportado por la bandeja: abre la conversación
            // directamente en vez de dejar al operador buscándola.
            'url' => '/chats?chat='.$chat->id,
            'tag' => WebPushDispatcher::chatMessageTag((string) $chat->id),
            'data' => [
                'type' => 'chat_message',
                'chat_id' => $chat->id,
            ],
        ];

        foreach ($subs as $sub) {
            $user = $sub->user;

            if (! $user instanceof User) {
                continue;
            }

            if (! WebPushDispatcher::userCanReceiveChatMessage($user, $companyNit, $chat->branch_id)) {
                continue;
            }

            $dispatcher->send($sub, $payload);
        }
    }

    /**
     * Recorte corto del cuerpo. El aviso llega al lock screen de un teléfono
     * ajeno al panel, así que se manda lo mínimo para decidir si atender: nunca
     * el mensaje completo.
     */
    private function preview(ChatMessage $message): string
    {
        $body = trim((string) $message->body);

        if ($body === '') {
            return 'Te enviaron un mensaje';
        }

        return mb_strimwidth($body, 0, 120, '…');
    }
}
