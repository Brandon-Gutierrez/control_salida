<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Contenido leído del QR de un predio. La prueba de ubicación la valida el middleware EnsureLocationProof. */
class ScanQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'qrData' => ['required', 'string'],
        ];
    }
}
