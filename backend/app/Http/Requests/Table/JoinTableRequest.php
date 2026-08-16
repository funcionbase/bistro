<?php

declare(strict_types=1);

namespace App\Http\Requests\Table;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del formulario público de unión a mesa.
 *
 * El servicio re-normaliza y re-valida el teléfono internamente; aquí solo
 * hacemos un guardado básico de forma y longitud para devolver mensajes UX
 * claros antes de pegarle a la BD.
 */
class JoinTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            // Alfabeto español: solo letras (con tildes), ñ y espacios.
            // Sin dígitos, sin apóstrofos, sin guiones, sin ningún símbolo.
            'display_name' => [
                'required',
                'string',
                'min:2',
                'max:80',
                'regex:/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u',
            ],
            // Solo dígitos. El normalizador del servicio termina de validar
            // que sea celular colombiano (10 dig empezando en 3).
            'phone' => [
                'required',
                'string',
                'min:7',
                'max:20',
                'regex:/^[0-9]+$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'display_name.required' => 'Escribe tu nombre.',
            'display_name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'display_name.max' => 'El nombre no puede pasar de 80 caracteres.',
            'display_name.regex' => 'El nombre solo puede llevar letras (sin números ni símbolos).',
            'phone.required' => 'Escribe tu celular.',
            'phone.min' => 'El celular es muy corto.',
            'phone.max' => 'El celular es muy largo.',
            'phone.regex' => 'El celular solo puede llevar números.',
        ];
    }
}
