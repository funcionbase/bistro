<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del pedido público sin mesa desde el QR de menú de sede
 * (`/menus?branch={menu_qr_token}`): para llevar o domicilio.
 *
 * Los precios NUNCA vienen del payload — el controller los resuelve del menú
 * activo de la sede (CLAUDE.md §13). Aquí solo se valida forma, longitud y
 * sanitización de los campos de texto libre.
 */
class StoreBranchOrderRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'customer_name' => 'plain_text_short',
        'address' => 'plain_text_short',
        'neighborhood' => 'plain_text_short',
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
            'type' => ['required', 'in:pickup,delivery'],
            // Mismo alfabeto que el join de mesa (#191): letras, tildes, ñ, espacios.
            'customer_name' => [
                'required',
                'string',
                'min:2',
                'max:80',
                'regex:/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u',
            ],
            // Solo dígitos; el controller normaliza a celular CO (10 dig, inicia en 3).
            'customer_phone' => ['required', 'string', 'min:7', 'max:20', 'regex:/^[0-9]+$/'],
            'address' => ['required_if:type,delivery', 'nullable', 'string', new SafePlainText(maxBytes: 160, allowWhitespace: true)],
            'neighborhood' => ['required_if:type,delivery', 'nullable', 'string', new SafePlainText(maxBytes: 80, allowWhitespace: true)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.id' => ['required', 'string', 'max:64'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', new SafePlainText(maxBytes: 200, allowWhitespace: true)],
            // Token de la sesión de carta enviada desde /chats (/menus?cart=).
            // Opcional: liga el pedido al chat que envió la carta.
            'cart_token' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_name.required' => 'Escribe tu nombre.',
            'customer_name.regex' => 'El nombre solo puede llevar letras (sin números ni símbolos).',
            'customer_phone.required' => 'Escribe tu celular.',
            'customer_phone.regex' => 'El celular solo puede llevar números.',
            'address.required_if' => 'Escribe la dirección de entrega.',
            'neighborhood.required_if' => 'Escribe el barrio.',
            'items.required' => 'El pedido no tiene productos.',
            'items.min' => 'El pedido no tiene productos.',
        ];
    }
}
