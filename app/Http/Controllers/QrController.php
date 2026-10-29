<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\Qr\QrTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QrController extends Controller
{
    public function __construct(private QrTokenService $tokens) {}

    /** Genera un token temporal para el QR del predio asignado al responsable autenticado. */
    public function storeForManager(Request $request): JsonResponse
    {
        $premise = $request->user()->premise;

        if (! $premise) {
            throw ApiException::failure(403, 'La cuenta no tiene un predio asignado.');
        }

        return response()->json($this->tokens->issue($premise));
    }
}
