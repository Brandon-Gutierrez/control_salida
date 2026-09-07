<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ReasonLeave;
use App\Repositories\PremiseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            'data' => $premises,
        ], Response::HTTP_OK);
    }

    /**
     * Obtiene el catálogo completo de motivos de salida.
     */
    public function getReasons(): JsonResponse
    {
        $reasons = ReasonLeave::all();

        return response()->json($reasons, Response::HTTP_OK);
    }

    /**
     * Registra un nuevo predio y asocia sus motivos de salida.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'reason_ids' => 'required|array',
            'reason_ids.*' => 'exists:reason_leaves,id',
        ]);

        $premise = $this->premiseRepository->create(
            ['name' => $validated['name']],
            $validated['reason_ids']
        );

        return response()->json($premise, Response::HTTP_CREATED);
    }

    /**
     * Sincroniza las razones de salida asignadas a un predio.
     */
    public function updateReasons(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason_ids' => 'required|array',
            'reason_ids.*' => 'exists:reason_leaves,id',
        ]);

        $premise = $this->premiseRepository->updateReasons($id, $validated['reason_ids']);

        return response()->json($premise, Response::HTTP_OK);
    }
}