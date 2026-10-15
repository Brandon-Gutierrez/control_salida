<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveReportController extends Controller
{
    /**
     * Reporte de salidas del periodo (por defecto, los últimos 30 días) con
     * filtros opcionales por predio, motivo y persona (nombre o item).
     */
    public function __invoke(Request $request, LeaveReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
            'premise_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:150'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $to = isset($data['to']) ? Carbon::createFromFormat('Y-m-d', $data['to']) : now();
        $from = isset($data['from']) ? Carbon::createFromFormat('Y-m-d', $data['from']) : now()->subDays(29);

        return response()->json([
            'status' => 0,
            'data' => $reports->build($from, $to, [
                'premise_id' => $data['premise_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'search' => $data['search'] ?? null,
            ]),
        ]);
    }
}
