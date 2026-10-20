<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmManualEntryRequest;
use App\Http\Requests\ValidateManualEntryRequest;
use App\Services\Leave\ManualEntryService;
use Illuminate\Http\JsonResponse;

/** Ingreso manual (item + carnet) operado desde la pantalla del responsable del predio. */
class ManualEntryController extends Controller
{
    public function __construct(private ManualEntryService $manual) {}

    /** Valida item y carnet; devuelve los motivos del predio o registra el retorno. */
    public function validateEntry(ValidateManualEntryRequest $request): JsonResponse
    {
        return response()->json($this->manual->validate(
            $request->user(),
            $request->validated('item'),
            $request->validated('carnet'),
        ));
    }

    /** Confirma la salida con el motivo elegido. */
    public function confirm(ConfirmManualEntryRequest $request): JsonResponse
    {
        $this->manual->confirm(
            $request->user(),
            $request->validated('item'),
            $request->validated('leaveTicket'),
            $request->validated('reason'),
        );

        return response()->json([
            'status' => 0,
            'message' => 'Salida temporal registrada correctamente',
        ]);
    }
}
