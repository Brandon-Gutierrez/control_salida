<?php

namespace App\Http\Controllers;

use App\Models\LeaveReason;
use App\Repositories\LeaveReasonRepository;
use App\Services\Leave\LeaveReasonSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Catálogo de motivos de salida. */
class ReasonController extends Controller
{
    /** Nombres de los motivos activos, ordenados alfabéticamente. */
    public function index(LeaveReasonRepository $reasons): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'reasons' => $reasons->allNames(),
        ]);
    }

    /**
     * Catálogo completo para la sección "Motivos de salida" del panel: los
     * activos y los que Comteco ya no ofrece (se conservan por el historial),
     * en cuántos predios se usa cada uno y el estado de la última sincronización.
     */
    public function catalog(LeaveReasonSyncService $sync): JsonResponse
    {
        $reasons = LeaveReason::withTrashed()
            ->withCount('premises as premises_count')
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveReason $reason) => [
                'reason_id' => $reason->reason_id,
                'name' => $reason->name,
                'code' => $reason->code,
                'active' => ! $reason->trashed(),
                'premises_count' => (int) $reason->premises_count,
            ])
            ->values();

        return response()->json([
            'status' => 0,
            'data' => [
                'reasons' => $reasons,
                'sync' => $sync->status(),
            ],
        ]);
    }

    /** Sincroniza el catálogo con el sistema externo (acción manual del administrador). */
    public function sync(Request $request, LeaveReasonSyncService $sync): JsonResponse
    {
        $result = $sync->sync(LeaveReasonSyncService::SOURCE_MANUAL, $request->user());

        $changed = $result['added'] + $result['updated'] + $result['reactivated'] + $result['deactivated'] > 0;

        return response()->json([
            'status' => 0,
            'message' => $changed
                ? 'Motivos de salida actualizados correctamente'
                : 'No se encontraron cambios en los motivos de salida',
            'result' => $result,
            'sync' => $sync->status(),
        ]);
    }
}