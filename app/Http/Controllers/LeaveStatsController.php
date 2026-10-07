<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveStatsController extends Controller
{
    /** Estadísticas de salidas de la persona autenticada: día, semana y mes en curso. */
    public function __invoke(Request $request, LeaveStatsService $stats): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => $stats->forUser($request->user()),
        ]);
    }
}
