<?php

declare(strict_types=1);

namespace App\Http\Requests\Whatsapp;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de un canal de WhatsApp (paso 1 del wizard, §8.3).
 *
 * `consent_accepted` es `accepted`, no `boolean`: la diferencia importa. Con
 * `boolean` un `false` explícito pasaría la validación y el canal se crearía sin
 * consentimiento; `accepted` exige que venga afirmativo. Es la evidencia de que
 * al cliente se le advirtió que WhatsApp puede bloquear el número, así que el
 * campo no puede ser opcional ni tener default.
 *
 * `branch_id` ausente o nulo = canal de toda la empresa. Que exista la sede y
 * que el usuario tenga acceso a ella se resuelve en el controlador, donde está
 * el `company_nit` de la credencial — acá no se consulta la base con un id que
 * todavía no se validó contra la empresa activa.
 */
class StoreWhatsappChannelRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'label' => 'plain_text_short',
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
            'label' => ['nullable', new SafePlainText(maxBytes: 120, allowWhitespace: true)],
            'consent_accepted' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'consent_accepted.accepted' => 'Tenés que aceptar el aviso de riesgo antes de conectar un número.',
        ];
    }
}
