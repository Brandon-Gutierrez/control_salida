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

    /**
     * Límite por persona y límite por motivo (general o personalizado),
     * iguales para todas las personas. Vacío = sin tope.
     */
    public function update(UpdateLeaveLimitsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $reasonLimit = $data['reason_limit'];

        $this->limits->savePolicy(
            $data['period'],
            $data['max_exits'],
            $reasonLimit['period'],
            $reasonLimit['mode'],
            $reasonLimit['general'],
            collect($reasonLimit['custom'] ?? [])->mapWithKeys(fn ($row) => [(int) $row['reason_id'] => $row['max']])->all(),
        );

        return response()->json(['status' => 0, 'data' => $this->limits->policy()]);
    }
}
