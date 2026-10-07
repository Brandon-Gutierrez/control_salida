<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePremiseReasonsRequest;
use App\Http\Resources\PremiseResource;
use App\Models\Premise;
use App\Repositories\PremiseRepository;
use App\Repositories\ReasonPremiseRepository;
use Illuminate\Http\JsonResponse;

/** Motivos de salida habilitados en un predio. */
class PremiseReasonController extends Controller
{
    /** Motivos del predio (app móvil: el predio se identifica por nombre). */
    public function index(Premise $premise, ReasonPremiseRepository $reasons): JsonResponse
    {
        return response()->json(['reasons' => $reasons->reasonNamesForPremise($premise->premise_id)]);
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
