<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Confirmación de la salida: comprobante del escaneo, predio y motivo elegidos. */
class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qrData' => ['required_without:leaveTicket', 'nullable', 'string'],
            'leaveTicket' => ['required_without:qrData', 'nullable', 'string'],
            'namePremise' => ['required', 'string'],
            'nameReason' => ['required', 'string'],
        ];
    }

    /** Comprobante del escaneo; `qrData` se acepta como alias de `leaveTicket`. */
    public function leaveTicket(): string
    {
        return trim($this->validated('leaveTicket') ?? $this->validated('qrData') ?? '');
    }
}
