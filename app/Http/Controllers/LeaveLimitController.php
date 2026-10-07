<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLeaveLimitsRequest;
use App\Services\Leave\LeaveLimitService;
use Illuminate\Http\JsonResponse;

class LeaveLimitController extends Controller
{
    public function __construct(private LeaveLimitService $limits) {}

    public function show(): JsonResponse
    {
        return response()->json(['status' => 0, 'data' => $this->limits->policy()]);
    }

    /** Un solo límite de salidas para todas las personas. Vacío = sin tope. */
    public function update(UpdateLeaveLimitsRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->limits->savePolicy($data['period'], $data['max_exits'], $data['max_exits_per_premise']);

        return response()->json(['status' => 0, 'data' => $this->limits->policy()]);
    }
}
