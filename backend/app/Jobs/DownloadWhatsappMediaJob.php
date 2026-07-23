<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\Whatsapp\MetaGraphApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Descarga un media (sticker, imagen, etc.) recibido por webhook de WhatsApp
 * y lo guarda en disk public para que el frontend lo muestre.
 *
 * Flujo:
 *   1. Resuelve el ChatMessage y el canal del chat (`resolveWhatsappChannel`)
 *      para conseguir el access_token del cliente.
 *   2. Pide a Meta /{media_id} la URL temporal de descarga (~5 min de vida).
 *   3. Descarga los bytes con el access_token.
 *   4. Guarda en chat-media/{chat_id}/{message_id}.{ext} y persiste media_path.
 *
 * Si algo falla (token invalido, URL expirada, Meta caido) reintentamos hasta
 * 3 veces con backoff. Despues de eso el mensaje se queda sin media_path y el
 * frontend muestra un placeholder.
 */
class DownloadWhatsappMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> backoff en segundos para cada reintento */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $chatMessageId,
    ) {}

    public function handle(): void
    {
        /** @var ChatMessage|null $message */
        $message = ChatMessage::query()->with('chat')->find($this->chatMessageId);

        if ($message === null || empty($message->media_meta_id) || ! empty($message->media_path)) {
            return;
        }

        $chat = $message->chat;
        if ($chat === null) {
            return;
        }

        // Multi-canal (F1): la media se descarga con el token del canal por el
        // que entró el mensaje.
        $account = $chat->resolveWhatsappChannel();

        if ($account === null || empty($account->accessToken())) {
            Log::channel('single')->warning('whatsapp.media.skip_no_account', [
                'message_id' => $this->chatMessageId,
            ]);

            return;
        }

        $graph = MetaGraphApiClient::forCurrentEnvironment();
        if ($graph === null) {
            Log::channel('single')->warning('whatsapp.media.skip_no_graph', [
                'message_id' => $this->chatMessageId,
            ]);

            return;
        }

        $token = $account->accessToken();

        $metadata = $graph->getMediaMetadata($message->media_meta_id, $token);

        if (empty($metadata['url'])) {
            Log::channel('single')->warning('whatsapp.media.no_url', [
                'message_id' => $this->chatMessageId,
                'media_meta_id' => $message->media_meta_id,
            ]);

            $this->release(60);

            return;
        }

        $bytes = $graph->downloadMediaBinary($metadata['url'], $token);

        if ($bytes === null) {
            Log::channel('single')->warning('whatsapp.media.download_failed', [
                'message_id' => $this->chatMessageId,
            ]);

            $this->release(60);

            return;
        }

        $extension = $this->extensionFor($metadata['mime_type'] ?? $message->media_mime);
        // CIBER-04: %s con los UUID completos. Antes era %d, que casteaba el
        // UUID a entero (solo el run de dígitos iniciales) → todas las media
        // colapsaban a `chat-media/<dígito>/<dígito>.ext`: colisión que
        // sobreescribía archivos entre clientes/empresas + keyspace enumerable.
        $relativePath = sprintf('chat-media/%s/%s.%s', $chat->id, $message->id, $extension);

        Storage::disk(config('filesystems.default'))->put($relativePath, $bytes);

        $message->forceFill([
            'media_path' => $relativePath,
            'media_mime' => $metadata['mime_type'] ?? $message->media_mime,
        ])->save();
    }

    private function extensionFor(?string $mime): string
    {
        return match ($mime) {
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/ogg' => 'ogg',
            'audio/mpeg' => 'mp3',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
