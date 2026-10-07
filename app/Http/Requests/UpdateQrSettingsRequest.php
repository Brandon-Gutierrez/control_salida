<?php

namespace App\Http\Requests;

use App\Services\Qr\QrSettingsService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateQrSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qr_ttl_seconds' => [
                'required',
                'integer',
                'min:'.QrSettingsService::TTL_MIN_SECONDS,
                'max:'.QrSettingsService::TTL_MAX_SECONDS,
            ],
        ];
    }
}
