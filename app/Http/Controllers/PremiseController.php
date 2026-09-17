<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Premise;
use App\Models\ReasonLeave;
use App\Repositories\PremiseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PremiseController extends Controller
{
    public function __construct(
        protected PremiseRepository $premiseRepository
    ) {}

    /**
     * Obtiene todos los predios con sus motivos asociados.
     */
    public function index(): JsonResponse
    {
        $premises = $this->premiseRepository->getAllWithReasons();

        return response()->json([
            "status" => 0,
            "data" => $premises,
        ], 200);
    }

    /**
     * Crea un predio y, opcionalmente le asigna motivos por nombre.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:premises,name'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ]);

        $premise = Premise::create(['name' => $data['name']]);
        $this->premiseRepository->syncReasonsByName($premise, $data['reasons'] ?? []);

        return response()->json([
            "status" => 0,
            "data" => $this->premiseRepository->toResource($premise),
        ], 201);
    }

    /**
     * Reemplaza los motivos permitidos de un predio.
     */
    public function updateReasons(Request $request, Premise $premise): JsonResponse
    {
        $data = $request->validate([
            'reasons' => ['present', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ]);

        $this->premiseRepository->syncReasonsByName($premise, $data['reasons']);

        return response()->json([
            "status" => 0,
            "data" => $this->premiseRepository->toResource($premise),
        ], 200);
    }

    /**
     * Obtiene el catálogo completo de motivos de salida.
     */
    public function reasons(): JsonResponse
    {
        $reasons = ReasonLeave::orderBy("name")->pluck("name");

        return response()->json([
            "status" => 0,
            "reasons" => $reasons
        ], 200);
    }
}
