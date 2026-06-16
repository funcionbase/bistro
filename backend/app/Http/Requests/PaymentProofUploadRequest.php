<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SanitizesInput;
use App\Rules\SafePlainText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentProofUploadRequest extends FormRequest
{
    use SanitizesInput;

    /** @var array<string, string> */
    protected array $sanitize = [
        'notes' => 'plain_text_long',
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
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'invoice_ids' => ['nullable', 'array', 'max:50'],
            'invoice_ids.*' => ['uuid', Rule::exists('invoices', 'id')],
            'notes' => ['nullable', new SafePlainText(maxBytes: 500, allowWhitespace: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Adjunta el comprobante de pago.',
            'file.max' => 'El comprobante no puede pesar más de 10 MB.',
            'file.mimes' => 'Formatos aceptados: PDF, JPG, JPEG, PNG.',
        ];
    }
}
