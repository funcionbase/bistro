<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/push/subscriptions — registra suscripción Web Push del
 * dispositivo (CA2 #149).
 *
 * El payload es el shape devuelto por `PushSubscription.toJSON()` del
 * navegador (endpoint + keys.p256dh + keys.auth). El backend persiste
 * upsert por (user_id, endpoint).
 *
 * Sanitización:
 *  - `endpoint`, `p256dh`, `auth`: passthrough con NFC (no plain_text — son
 *    identificadores opacos del navegador, no texto humano).
 *  - `user_agent`: plain_text_short (máx 500 bytes) — se muestra en UI de
 *    "dispositivos suscritos" como hint.
 */
class StorePushSubscriptionRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'user_agent' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'url', new SafePlainText(maxBytes: 2048, allowWhitespace: false)],
            'p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
            'auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', new SafePlainText(maxBytes: 255, allowWhitespace: false)],
            'user_agent' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }
}
