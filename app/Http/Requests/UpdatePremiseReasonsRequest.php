<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePremiseReasonsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reasons' => ['present', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ];
    }
}
