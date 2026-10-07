<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScanQrRequest;
use App\Services\Qr\QrScanService;
use Illuminate\Http\JsonResponse;

class QrScanController extends Controller
{
    /** Escaneo de un QR de predio: inicia la salida o registra el retorno. */
    public function __invoke(ScanQrRequest $request, QrScanService $scan): JsonResponse
    {
        return response()->json($scan->scan($request->user(), $request->validated('qrData')));
    }
}
