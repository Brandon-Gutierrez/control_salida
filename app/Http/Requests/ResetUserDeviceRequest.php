<?php

namespace App\Http\Requests;

use App\Support\ClientPlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResetUserDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in(ClientPlatform::ALL)],
        ];
    }
}
