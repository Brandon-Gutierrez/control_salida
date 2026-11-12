<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePremiseRequest;
use App\Http\Requests\UpdatePremiseRequest;
use App\Http\Resources\PremiseResource;
use App\Models\Premise;
use App\Repositories\PremiseRepository;
use App\Services\Location\PremiseLocationService;
use App\Services\Premise\PremiseManagerService;
use Illuminate\Http\JsonResponse;

class PremiseController extends Controller
{
    public function __construct(
        private PremiseRepository $premises,
        private PremiseManagerService $managers,
        private PremiseLocationService $locations,
    ) {}

    /**
     * Todos los predios con su responsable y sus motivos, y el radio (metros)
     * dentro del cual se aceptan los escaneos: la web dibuja ese mismo radio.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => PremiseResource::collection($this->premises->allWithReasons())->resolve(),
            'geofence_radius_m' => $this->locations->radiusMeters(),
        ]);
    }

    /** Crea un predio y, opcionalmente, le asigna motivos por nombre y un responsable. */
    public function store(StorePremiseRequest $request): JsonResponse
    {
        $data = $request->validated();

        $premise = $this->premises->create([
            'name' => $data['name'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
        $this->premises->syncReasonsByName($premise, $data['reasons'] ?? []);
        if (! empty($data['manager_user_id'])) {
            $this->managers->assign($premise, (int) $data['manager_user_id'], $request->user());
        }

        return response()->json([
            'status' => 0,
            'data' => PremiseResource::make($premise)->resolve(),
        ], 201);
    }

    /** Actualiza nombre, ubicación, motivos y/o responsable del predio. */
    public function update(UpdatePremiseRequest $request, Premise $premise): JsonResponse
    {
        $data = $request->validated();

        $this->premises->update($premise, collect($data)->only(['name', 'latitude', 'longitude'])->all());
        if (array_key_exists('reasons', $data)) {
            $this->premises->syncReasonsByName($premise, $data['reasons']);
        }
        if (array_key_exists('manager_user_id', $data)) {
            $this->managers->assign(
                $premise,
                $data['manager_user_id'] === null ? null : (int) $data['manager_user_id'],
                $request->user(),
            );
        }

        return response()->json([
            'status' => 0,
            'data' => PremiseResource::make($premise->fresh()->unsetRelations())->resolve(),
        ]);
    }
}
