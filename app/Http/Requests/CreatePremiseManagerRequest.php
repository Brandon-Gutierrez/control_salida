<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePremiseManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
            'premise_id' => ['required', 'integer', 'exists:premises,premise_id'],
        ];
    }
}
