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

            'reason_limit' => ['required', 'array'],
            'reason_limit.period' => ['required', 'string', Rule::in(LeaveLimitService::PERIODS)],
            'reason_limit.mode' => ['required', 'string', Rule::in(LeaveLimitService::REASON_MODES)],
            'reason_limit.general' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'reason_limit.custom' => ['present', 'nullable', 'array'],
            'reason_limit.custom.*.reason_id' => ['required', 'integer', 'distinct', 'exists:reasons,reason_id'],
            'reason_limit.custom.*.max' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
