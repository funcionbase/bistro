<?php

declare(strict_types=1);

namespace App\Http\Requests\Chat;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de una respuesta rápida (§8.4b punto 7).
 *
 * `branch_id` ausente/nulo = respuesta de toda la empresa. Que la sede exista y
 * sea de la empresa activa lo resuelve el controlador con el `company_nit` de la
 * credencial. El `body` preserva saltos de línea (`plain_text_long`) porque una
 * respuesta puede ser de varias líneas; los `{{cliente}}`/`{{pedido}}`/`{{sede}}`
 * pasan intactos (no son HTML) y los resuelve el frontend al insertar.
 */
class StoreQuickReplyRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'title' => 'plain_text_short',
        'body' => 'plain_text_long',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid'],
            'title' => ['required', new SafePlainText(maxBytes: 80, allowWhitespace: true)],
            'body' => ['required', new SafePlainText(maxBytes: 2000, allowWhitespace: true)],
        ];
    }
}
