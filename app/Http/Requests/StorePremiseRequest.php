<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePremiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:premises,name'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,user_id'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ];
    }
}
