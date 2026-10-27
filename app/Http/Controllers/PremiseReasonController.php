<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePremiseReasonsRequest;
use App\Http\Resources\PremiseResource;
use App\Models\Premise;
use App\Repositories\PremiseRepository;
use App\Repositories\ReasonPremiseRepository;
use App\Services\Leave\LeaveLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Motivos de salida habilitados en un predio. */
class PremiseReasonController extends Controller
{
    /**
     * Motivos del predio (app móvil: el predio se identifica por nombre), y
     * cuáles de ellos la persona ya no puede elegir por haber llegado al
     * límite por motivo del período.
     */
    public function index(
        Request $request,
        Premise $premise,
        ReasonPremiseRepository $reasons,
        LeaveLimitService $limits,
    ): JsonResponse {
        $names = $reasons->reasonNamesForPremise($premise->premise_id);

        return response()->json([
            'reasons' => $names,
            'blocked_reasons' => $limits->blockedReasons($request->user(), $names),
        ]);
    }

    /** Reemplaza los motivos permitidos del predio (administración). */
    public function update(UpdatePremiseReasonsRequest $request, Premise $premise, PremiseRepository $premises): JsonResponse
    {
        $premises->syncReasonsByName($premise, $request->validated('reasons'));

        return response()->json([
            'status' => 0,
            'data' => PremiseResource::make($premise)->resolve(),
        ]);
    }
}
