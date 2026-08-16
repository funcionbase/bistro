<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Concerns\SanitizesInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Registro con correo/contraseña (complementario a Google OAuth).
 *
 * `website` es un honeypot: campo oculto que los humanos nunca llenan. No se
 * valida acá (el controller lo chequea y responde un éxito falso) para no
 * darle al bot una señal de que fue detectado.
 */
class RegisterRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'first_name' => 'plain_text_short',
        'last_name' => 'plain_text_short',
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
            // Mismo alfabeto que el enrollment y el join de mesa.
            'first_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u'],
            'last_name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[A-Za-zÁÉÍÓÚÜÑáéíóúüñ ]+$/u'],
            // SIN `unique`: revelar "ya existe" acá filtraría la existencia del
            // correo (enumeración). El controller maneja el duplicado sin
            // distinguir la respuesta (avisa al titular real por correo).
            'email' => ['required', 'string', 'email', 'max:255'],
            // Password::defaults() se configura en AppServiceProvider:
            // min 8 + uncompromised (rechaza contraseñas filtradas vía HIBP).
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'Escribe tus nombres.',
            'first_name.regex' => 'Los nombres solo pueden llevar letras.',
            'last_name.required' => 'Escribe tus apellidos.',
            'last_name.regex' => 'Los apellidos solo pueden llevar letras.',
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe una contraseña.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
