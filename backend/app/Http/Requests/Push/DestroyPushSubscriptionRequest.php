<?php

declare(strict_types=1);

namespace App\Http\Requests\Push;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * DELETE /api/v1/push/subscriptions — soft-revoke por endpoint (CA2 #149).
 *
 * Idempotente: si el endpoint no existe o ya está revocado, responde 204
 * igual. No exponemos 404 para no leak de información (qué endpoints
 * existen en BD).
 */
class DestroyPushSubscriptionRequest extends FormRequest
{
    use SanitizesInput;

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
        ];
    }
}
