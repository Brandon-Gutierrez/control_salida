<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveStatsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyLeavesController extends Controller
{
    /**
     * Detalle de las salidas de la persona autenticada entre dos fechas
     * (`from` y `to`, Y-m-d, ambas incluidas): predio, motivo, hora de salida,
     * hora de retorno y duración. La más reciente primero.
     */
    public function __invoke(Request $request, LeaveStatsService $stats): JsonResponse
    {
        $dates = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return response()->json([
            'status' => 0,
            'data' => $stats->leavesInRange(
                $request->user(),
                Carbon::createFromFormat('Y-m-d', $dates['from']),
                Carbon::createFromFormat('Y-m-d', $dates['to']),
            ),
        ]);
    }
}
