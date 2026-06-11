<?php

namespace App\Http\Requests\Settings;

use App\Services\CompanySettingsService;
use App\Services\FeaturePermissionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/company/settings (CompanySettingsController). Solo owner/admin (user_role del JWT).
 *
 * Las claves válidas están definidas en CompanySettingsService::ALLOWED_KEYS; claves desconocidas fallan validación.
 * payment_methods acepta: efectivo, transferencia, tarjeta, nequi, daviplata.
 * menu_primary_color debe ser hex de 6 dígitos (ej: #FF6B35).
 * Los booleanos 'order_auto_confirm' y 'order_notify_customer_email' se normalizan en prepareForValidation().
 */
class UpdateCompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Mismo razonamiento que UpdateCompanyRequest: la autoridad es el
        // permiso RBAC `company.update,update`, no el alias `user_role`
        // que solo distingue owner/admin/member por nombre.
        return app(FeaturePermissionService::class)->hasPermission($this, 'company', 'update');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $allowedKeys = CompanySettingsService::ALLOWED_KEYS;

        return [
            'settings' => [
                'required',
                'array',
                function (string $attribute, mixed $value, callable $fail) use ($allowedKeys): void {
                    if (! is_array($value)) {
                        return;
                    }
                    $unknown = array_diff(array_keys($value), $allowedKeys);
                    if (count($unknown) > 0) {
                        $fail('Claves de configuración no permitidas: '.implode(', ', $unknown));
                    }
                },
            ],
            'settings.timezone' => ['sometimes', 'string', Rule::in(['America/Bogota'])],
            'settings.currency' => ['sometimes', 'string', Rule::in(['COP'])],
            'settings.currency_symbol' => ['sometimes', 'string', 'max:5'],
            'settings.language' => ['sometimes', 'string', Rule::in(['es'])],
            'settings.order_auto_confirm' => ['sometimes', 'boolean'],
            'settings.order_notify_customer_email' => ['sometimes', 'boolean'],
            'settings.bot_welcome_message' => ['sometimes', 'string', 'max:500'],
            'settings.bot_away_message' => ['sometimes', 'string', 'max:500'],
            'settings.delivery_area_km' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'settings.min_order_amount' => ['sometimes', 'integer', 'min:0'],
            'settings.payment_methods' => ['sometimes', 'array', 'min:1'],
            'settings.payment_methods.*' => [
                'string',
                Rule::in(['efectivo', 'transferencia', 'tarjeta', 'nequi', 'daviplata']),
            ],
            'settings.payment_method_accounts' => ['sometimes', 'array'],
            'settings.payment_method_accounts.*' => ['nullable', 'string', 'max:100'],
            'settings.menu_primary_color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'settings.whatsapp_read_receipts' => ['sometimes', 'boolean'],
            // Threshold de margen para alertas de food cost: decimal entre 0.01 y 1.00.
            // Lo aceptamos como string para no perder precisión en serialización.
            'settings.food_cost_alert_threshold' => ['sometimes', 'string', 'regex:/^(0(\.\d{1,2})?|1(\.0{1,2})?)$/'],
            // Fidelización. points_per_cop = puntos otorgados por cada $1 COP.
            // Rango razonable: 0 (programa apagado) a 1 (1 punto por peso).
            // El frontend muestra el ratio como "puntos cada $1.000 COP" y
            // divide entre 1000 antes de mandar el valor decimal aquí.
            // Las claves loyalty se almacenan PLANAS con punto literal
            // ('loyalty.enabled', no anidado loyalty->enabled). Hay que escapar
            // el punto (`\.`) para que Laravel no lo trate como nivel anidado:
            // sin escapar, la regla nunca matchea el dato plano y, por
            // `excludeUnvalidatedArrayKeys`, validated() descarta TODO el array
            // `settings` → "Undefined array key settings" al guardar loyalty.
            'settings.loyalty\.enabled' => ['sometimes', 'boolean'],
            'settings.loyalty\.points_per_cop' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'settings.required' => 'El campo settings es obligatorio.',
            'settings.array' => 'El campo settings debe ser un objeto.',
            'settings.timezone.in' => 'La zona horaria ingresada no es válida.',
            'settings.language.in' => 'El idioma debe ser "es" o "en".',
            'settings.order_auto_confirm.boolean' => 'El valor debe ser verdadero o falso.',
            'settings.order_notify_customer_email.boolean' => 'El valor debe ser verdadero o falso.',
            'settings.delivery_area_km.min' => 'El área de entrega debe ser al menos 1 km.',
            'settings.delivery_area_km.max' => 'El área de entrega no puede superar 100 km.',
            'settings.min_order_amount.min' => 'El monto mínimo no puede ser negativo.',
            'settings.payment_methods.min' => 'Debe seleccionar al menos un método de pago.',
            'settings.payment_methods.*.in' => 'Método de pago no permitido.',
            'settings.menu_primary_color.regex' => 'El color debe ser un valor hexadecimal válido (ej: #FF6B35).',
            'settings.food_cost_alert_threshold.regex' => 'El umbral debe ser un decimal entre 0 y 1 (ej: 0.30 para 30%).',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalizar booleans enviados como string desde el frontend
        $settings = $this->input('settings', []);

        if (is_array($settings)) {
            foreach (['order_auto_confirm', 'order_notify_customer_email', 'whatsapp_read_receipts', 'loyalty.enabled'] as $boolKey) {
                if (array_key_exists($boolKey, $settings)) {
                    $settings[$boolKey] = filter_var($settings[$boolKey], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $settings[$boolKey];
                }
            }

            $this->merge(['settings' => $settings]);
        }
    }
}
