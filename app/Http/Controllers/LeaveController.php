<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLeaveRequest;
use App\Services\Leave\LeaveRegistrationService;
use Illuminate\Http\JsonResponse;

class LeaveController extends Controller
{
    /** Confirma la salida de la persona autenticada. */
    public function store(StoreLeaveRequest $request, LeaveRegistrationService $leaves): JsonResponse
    {
        $leaves->register(
            $request->user(),
            $request->leaveTicket(),
            $request->validated('namePremise'),
            $request->validated('nameReason'),
        );

        return response()->json([
            'status' => 0,
            'message' => 'Salida temporal registrada correctamente',
        ]);
    }
}
