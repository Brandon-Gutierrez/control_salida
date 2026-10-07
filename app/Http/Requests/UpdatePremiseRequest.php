<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePremiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('premises', 'name')->ignore($this->route('premise')->premise_id, 'premise_id'),
            ],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,user_id'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ];
    }
}
