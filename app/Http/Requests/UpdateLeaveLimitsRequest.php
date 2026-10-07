<?php

namespace App\Http\Requests;

use App\Services\Leave\LeaveLimitService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLeaveLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', Rule::in(LeaveLimitService::PERIODS)],
            'max_exits' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_exits_per_premise' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
