<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Premise;
use App\Models\ReasonLeave;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use Illuminate\Support\Facades\DB;
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
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,user_id'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ]);

        $premise = Premise::create([
            'name' => $data['name'],
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ]);
        $this->premiseRepository->syncReasonsByName($premise, $data['reasons'] ?? []);
        if (!empty($data['manager_user_id'])) {
            $error = $this->assignManager($premise, (int) $data['manager_user_id']);
            if ($error) {
                return $error;
            }
        }

        return response()->json([
            "status" => 0,
            "data" => $this->premiseRepository->toResource($premise),
        ], 201);
    }

    /** Actualiza nombre y/o ubicación del predio. */
    public function update(Request $request, Premise $premise): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:premises,name,' . $premise->premise_id . ',premise_id'],
            'latitude' => ['sometimes', 'required', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'required', 'numeric', 'between:-180,180', 'required_with:latitude'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,user_id'],
            'reasons' => ['sometimes', 'array'],
            'reasons.*' => ['string', 'exists:reasons,name'],
        ]);

        $premise->update(collect($data)->only(['name', 'latitude', 'longitude'])->all());
        if (array_key_exists('reasons', $data)) {
            $this->premiseRepository->syncReasonsByName($premise, $data['reasons']);
        }
        if (array_key_exists('manager_user_id', $data)) {
            $error = $this->assignManager($premise, $data['manager_user_id'] === null ? null : (int) $data['manager_user_id']);
            if ($error) {
                return $error;
            }
        }

        return response()->json([
            'status' => 0,
            'data' => $this->premiseRepository->toResource($premise->fresh()->unsetRelations()),
        ], 200);
    }

    /**
     * Actualiza los motivos permitidos de un predio.
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

    /**
     * Deja a un usuario MANAGE_PREMISE como único responsable del predio (o lo
     * deja sin responsable si es null). El responsable anterior queda sin predio
     * y sus sesiones se cierran, porque una cuenta sin predio no puede operar.
     */
    private function assignManager(Premise $premise, ?int $userId): ?JsonResponse
    {
        $newManager = null;
        if ($userId !== null) {
            $newManager = User::find($userId);
            if (!$newManager || !$newManager->isPremiseManager()) {
                return response()->json([
                    'status' => 1,
                    'message' => 'El responsable debe ser un usuario con el rol MANAGE_PREMISE.',
                ], 422);
            }
        }

        DB::transaction(function () use ($premise, $newManager) {
            $previous = User::where('premise_id', $premise->premise_id)
                ->when($newManager, fn ($q) => $q->where('user_id', '!=', $newManager->user_id))
                ->whereHas('role', fn ($q) => $q->whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE]))
                ->get();

            foreach ($previous as $user) {
                $user->update(['premise_id' => null]);
                UserActiveSession::where('user_id', $user->user_id)->delete();
            }
            if ($newManager) {
                $newManager->update(['premise_id' => $premise->premise_id]);
            }
        });

        return null;
    }
}
