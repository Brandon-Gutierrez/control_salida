<?php

namespace App\Http\Controllers;

use App\Repositories\LeaveReasonRepository;
use App\Services\Leave\LeaveReasonSyncService;
use Illuminate\Http\JsonResponse;

/** Catálogo de motivos de salida. */
class ReasonController extends Controller
{
    /** Nombres de todos los motivos, ordenados alfabéticamente. */
    public function index(LeaveReasonRepository $reasons): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'reasons' => $reasons->allNames(),
        ]);
    }

    /** Sincroniza el catálogo con el sistema externo. */
    public function sync(LeaveReasonSyncService $sync): JsonResponse
    {
        $changed = $sync->sync();

        if (! $changed) {
            return response()->json([
                'status' => 0,
                'message' => 'No se encontraron motivos de salida nuevas',
            ]);
        }

        return response()->json([
            'status' => 0,
            'message' => 'Motivos de salida actualizados correctamente',
            'newPremises' => $changed,
        ]);
    }
}
