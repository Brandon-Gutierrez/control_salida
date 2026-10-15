<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveStatsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveStatsController extends Controller
{
    /**
     * Estadísticas de salidas de la persona autenticada: día, semana y mes en
     * curso o, si se envían `from` y `to` (Y-m-d), ese rango de fechas.
     */
    public function __invoke(Request $request, LeaveStatsService $stats): JsonResponse
    {
        $user = $request->user();

        if ($request->hasAny(['from', 'to'])) {
            $dates = $request->validate([
                'from' => ['required', 'date_format:Y-m-d'],
                'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            ]);

            return response()->json([
                'status' => 0,
                'data' => [
                    'range' => $stats->forRange(
                        $user,
                        Carbon::createFromFormat('Y-m-d', $dates['from']),
                        Carbon::createFromFormat('Y-m-d', $dates['to']),
                    ),
                ],
            ]);
        }

        return response()->json([
            'status' => 0,
            'data' => $stats->forUser($user),
        ]);
    }
}
