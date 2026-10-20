<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Item y carnet de la persona que ingresa manualmente. */
class ValidateManualEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item' => ['required', 'string', 'regex:/^\d{1,10}$/'],
            'carnet' => ['required', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'item' => trim((string) $this->input('item')),
            'carnet' => trim((string) $this->input('carnet')),
        ]);
    }
}
