<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePremiseManagerRequest;
use App\Services\Premise\PremiseManagerService;
use Illuminate\Http\JsonResponse;

class PremiseManagerController extends Controller
{
    /** Crea una cuenta local responsable de un único predio. */
    public function store(CreatePremiseManagerRequest $request, PremiseManagerService $managers): JsonResponse
    {
        [$user, $generatedPassword] = $managers->create($request->validated());

        return response()->json([
            'status' => 0,
            'data' => $user,
            'generated_password' => $generatedPassword,
        ], 201);
    }
}
