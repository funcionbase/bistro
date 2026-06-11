<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class StoreTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // Slug-style: lowercase, dígitos, guion bajo y guion. Sin espacios para
        // mantener consistencia (UI lowercases antes de enviar). Max 50 char.
        return [
            'tag' => ['required', 'string', 'min:1', 'max:50', 'regex:/^[a-z0-9_\-]+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tag.regex' => 'La etiqueta solo puede contener letras minúsculas, dígitos, guion y guion bajo.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tag') && is_string($this->input('tag'))) {
            $this->merge(['tag' => strtolower(trim($this->input('tag')))]);
        }
    }
}
