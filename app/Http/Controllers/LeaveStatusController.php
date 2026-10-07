<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveStatusController extends Controller
{
    /** Datos y estado de salida de la persona autenticada. */
    public function __invoke(Request $request, LeaveStatusService $status): JsonResponse
    {
        return response()->json($status->forUser($request->user()));
    }
}
