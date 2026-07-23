<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use Illuminate\Support\Carbon;

/**
 * Mensaje entrante ya normalizado, sin rastro del proveedor que lo originó
 * (plan 8-whatsapp.md §6.4).
 *
 * Es la UNIÓN de los dos modelos, no el de uno solo, porque durante F2-F4
 * conviven Meta y Evolution:
 *
 *  - Meta entrega un `mediaProviderId` y la descarga se hace después contra
 *    Graph (`DownloadWhatsappMediaJob`). Llega con `mediaBase64` en null.
 *  - Evolution entrega los bytes DENTRO del evento (`base64: true`) y no hay
 *    forma de pedirlos más tarde: `getBase64FromMediaMessage` lee la tabla
 *    "Message", que está vacía por la minimización de datos de §4.6. Llega con
 *    `mediaProviderId` en null y el binario en `mediaBase64`.
 *
 * `mediaPayload` transporta lo estructurado que no es binario — ubicación y
 * contacto no tienen archivo ni `media_path`, solo campos.
 */
final class NormalizedInboundMessage
{
    /**
     * @param  ?array<string, mixed>  $mediaPayload
     */
    public function __construct(
        public readonly string $clientPhoneE164,
        public readonly ?string $clientName,
        public readonly string $body,
        public readonly ?string $providerMessageId,
        public readonly Carbon $sentAt,
        public readonly bool $fromMe = false,
        public readonly ?string $mediaType = null,
        public readonly ?string $mediaProviderId = null,
        public readonly ?string $mediaMime = null,
        public readonly ?string $mediaBase64 = null,
        public readonly ?array $mediaPayload = null,
    ) {}

    /** Hay binario que guardar, venga embebido o por descargar. */
    public function hasMedia(): bool
    {
        return $this->mediaBase64 !== null || $this->mediaProviderId !== null;
    }
}
