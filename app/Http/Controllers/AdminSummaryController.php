<?php

namespace App\Http\Controllers;

use App\Services\Account\AdminSummaryService;
use Illuminate\Http\JsonResponse;

/** Contadores de la pantalla de inicio del panel de administración. */
class AdminSummaryController extends Controller
{
    public function __invoke(AdminSummaryService $summary): JsonResponse
    {
        return response()->json(['status' => 0, 'data' => $summary->summary()]);
    }
}
