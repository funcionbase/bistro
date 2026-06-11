<?php

namespace App\Http\Requests\Company;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use App\Services\FeaturePermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Endpoint: PUT /api/company (CompanyController::update). Gated por permiso RBAC company.update,update.
 *
 * Todos los campos son opcionales (sometimes). Los archivos qr_code y logo son multipart; máx 5MB.
 * El logo acepta además SVG y WebP. El NIT de la empresa no es editable.
 */
class UpdateCompanyRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'commercial_name' => 'plain_text_short',
        'legal_name' => 'plain_text_short',
        'account_number' => 'identifier',
        'breb_key' => 'plain_text_short',
        'default_tax_label' => 'plain_text_short',
    ];

    public function authorize(): bool
    {
        // Antes el authorize() chequeaba `user_role in [owner, admin]`. Eso
        // bloqueaba a roles personalizados con permiso `company.update`
        // (que sí pasaban el middleware `permission:company.update,update`),
        // forzando 403 en el FormRequest. Defense-in-depth correcta es
        // delegar al mismo servicio RBAC que el middleware.
        return app(FeaturePermissionService::class)->hasPermission($this, 'company', 'update');
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'commercial_name' => ['sometimes', new SafePlainText(maxBytes: 255)],
            'legal_name' => ['sometimes', new SafePlainText(maxBytes: 255)],
            'bank_id' => ['sometimes', 'uuid', 'exists:banks,id'],
            'account_number' => ['sometimes', 'string', 'max:50'],
            'account_type' => ['sometimes', 'string', Rule::in(['corriente', 'ahorros'])],
            'breb_key' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 255)],
            // Límite de 5 MB (5120 KB) para QR y logo. La rule `max:` de Laravel
            // recibe kilobytes; 5 MB = 5 * 1024 KB.
            'qr_code' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            // El logo acepta SVG además de raster. La rule `image` de Laravel
            // sólo valida raster (jpeg/png/gif/bmp/webp/heic) y rechaza SVG —
            // por eso usamos `file` + `mimes` en su lugar. La negociación del
            // MIME real la hace `mimes:` resolviendo la extensión, así que el
            // archivo no puede colarse renombrado.
            'logo' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,svg,webp', 'max:5120'],

            // Configuración tributaria (CO). Los presets vienen de config/taxes.php.
            'tax_regime' => ['sometimes', Rule::in(config('taxes.available_regimes'))],
            'default_tax_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'default_tax_label' => ['sometimes', 'nullable', new SafePlainText(maxBytes: 60)],
            'tax_included_in_price' => ['sometimes', 'boolean'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->sanitizeMappedFields();

        // Normaliza booleanos enviados como string desde forms multipart.
        if ($this->has('tax_included_in_price')) {
            $this->merge([
                'tax_included_in_price' => filter_var($this->input('tax_included_in_price'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
