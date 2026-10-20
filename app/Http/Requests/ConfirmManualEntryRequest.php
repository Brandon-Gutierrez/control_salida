<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Confirmación del ingreso manual: persona, comprobante y motivo elegido. */
class ConfirmManualEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'item' => ['required', 'string', 'regex:/^\d{1,10}$/'],
            'leaveTicket' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:150'],
        ];
    }
}
