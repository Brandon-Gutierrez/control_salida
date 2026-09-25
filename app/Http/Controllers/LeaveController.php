<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\UserRepository;
use App\Repositories\ReasonLeaveRepository;
use App\Repositories\PremiseRepository;
use App\Models\Premise;
use App\Services\LeaveQuotaService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeaveController extends Controller
{
    //constructor 
    protected ReasonPremiseRepository $reasonPremiseRepository;
    protected UserRepository $userRepository;
    protected ReasonLeaveRepository $reasonLeaveRepository;
    protected PremiseRepository $premiseRepository;
    protected LeaveQuotaService $leaveQuotaService;

    
    public function __construct(ReasonPremiseRepository $reasonPremiseRepository,
                                UserRepository $userRepository,
                                ReasonLeaveRepository $reasonLeaveRepository,
                                PremiseRepository $premiseRepository,
                                LeaveQuotaService $leaveQuotaService)
    {
        $this->reasonPremiseRepository = $reasonPremiseRepository;
        $this->userRepository = $userRepository;
        $this->reasonLeaveRepository = $reasonLeaveRepository;
        $this->premiseRepository = $premiseRepository;
        $this->leaveQuotaService = $leaveQuotaService;
    }

    //Sincroniza el catalogo de motivos de salida con el servicio externo
    public function syncReasons()
    {
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->post(env('API_GETREASONS'), [ 
            'in_Entidad' => 'MOTIVO_SALIDA',
            'in_nombre_maq' => '',
        ]);
        //Verifica si hay un error en los datos 
        if ($response->failed() || $response->json("data") == null)
        {
            //retorna esetado de error
            return response()->json([
                "status" => 1,
                "message" => "Error al obtener los motivos de salida",
            ], 400);
        }
        $isNewData = $this->reasonLeaveRepository->syncReasons($response['data']);
        if (!$isNewData)
        {
            return response()->json([
                "status" => 0,
                "message" => "No se encontraron motivos de salida nuevas",
            ], 200);
        }
        return response()->json([
            "status" => 0,
            "message" => "Motivos de salida actualizados correctamente",
            "newPremises" => $isNewData,
        ], 200);
    }

    //Obtener los motivos de salida de un predio
    public function premiseReasons(Premise $premise)
    {
        $data = $this->reasonPremiseRepository->getReasonsOfPremise($premise->premise_id);
        return response()->json(['reasons' => $data], 200);
    }

    //Confirmar la salida del usuario autenticado
    public function store(Request $request)
    {
        $data = $request->validate([
            'qrData' => ['required_without:leaveTicket', 'nullable', 'string'],
            'leaveTicket' => ['required_without:qrData', 'nullable', 'string'],
            'namePremise' => ['required', 'string'],
            'nameReason' => ['required', 'string'],
        ]);
        $leaveTicket = trim($data['leaveTicket'] ?? $data['qrData'] ?? '');
        if ($leaveTicket === '') {
            return response()->json([
                'status' => 1,
                'code' => 'LEAVE_TICKET_INVALID',
                'message' => 'Falta el comprobante del escaneo. Vuelva a escanear el QR.',
                'retryable' => false,
            ], 422);
        }

        $item = $request->user()->item;
        $namePremise = $data["namePremise"];
        $nameReason = $data["nameReason"];
        try {
            $ticketData = Redis::get('leave-ticket:' . $leaveTicket);
        } catch (Throwable $exception) {
            Log::error('No se pudo validar el ticket de salida en Redis.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'status' => 1,
                'code' => 'QR_SERVICE_UNAVAILABLE',
                'message' => 'No se pudo validar el escaneo por un problema temporal. Intente nuevamente.',
                'retryable' => true,
            ], 503);
        }

        $ticket = is_string($ticketData) ? json_decode($ticketData, true) : null;
        if (!is_array($ticket) || !isset($ticket['user_id'], $ticket['premise_id'])) {
            return response()->json([
                'status' => 1,
                'code' => 'LEAVE_TICKET_EXPIRED',
                'message' => 'El comprobante del escaneo venció. Vuelva a escanear el QR si sigue vigente; de lo contrario, solicite uno nuevo.',
                'retryable' => false,
            ], 410);
        }

        if ((int) $ticket['user_id'] !== (int) $request->user()->user_id) {
            return response()->json([
                'status' => 1,
                'code' => 'LEAVE_TICKET_INVALID',
                'message' => 'El escaneo no pertenece a esta cuenta. Vuelva a escanear el QR.',
                'retryable' => false,
            ], 403);
        }

        $premiseId = (int) $ticket['premise_id'];

        try {
            $response = Http::connectTimeout(3)->timeout(8)->withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETEMPLOYEE'), ['item' => $item]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio de empleados no disponible al registrar salida.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return $this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE');
        }

        if ($response->failed()) {
            Log::warning('El servicio de empleados respondió con error al registrar salida.', [
                'user_id' => $request->user()->user_id,
                'http_status' => $response->status(),
            ]);

            return $this->temporaryFailure(
                'EMPLOYEE_SERVICE_UNAVAILABLE',
                $response->serverError() || $response->status() === 429,
            );
        }

        if ($response->json('status') == 1) {
            return response()->json([
                'status' => 1,
                'code' => 'EMPLOYEE_NOT_IDENTIFIED',
                'message' => 'No se pudo identificar al usuario en el sistema. Verifique la cuenta e intente nuevamente.',
                'retryable' => false,
            ], 422);
        }
        
        // Obtener el motivo local y comprobarlo contra el predio asociado al QR escaneado.
        $reasonId = $this->reasonPremiseRepository->getReasonId($nameReason);
        $requestedPremiseId = $this->premiseRepository->getPremiseId($namePremise);
        if (!$requestedPremiseId || $premiseId !== (int) $requestedPremiseId) {
            return response()->json([
                'status' => 1,
                'message' => 'El predio seleccionado no coincide con el predio del código QR.',
            ], 403);
        }

        //Busca el id en la tabla pivote(reason_premise)
        $reason_premise_id = ($premiseId && $reasonId)
            ? $this->reasonPremiseRepository->findAReasonPremise($premiseId, $reasonId)
            : null;
        if (!$reason_premise_id)
        {
            return response()->json([
                'status' => 1,
                'code' => 'REASON_NOT_AVAILABLE_FOR_PREMISE',
                'message' => 'El motivo de salida no está disponible para el predio seleccionado.',
                'retryable' => false,
            ], 422);
        }

        $quotaExceeded = $this->leaveQuotaService->check($request->user(), $premiseId);
        if ($quotaExceeded) {
            return response()->json($this->leaveQuotaService->rejectionPayload($quotaExceeded), 403);
        }

        //Registrar la salida temporal del usuario
        //LOGICA CON API
        $codeReason = $this->reasonLeaveRepository->getCodeReason($nameReason);
        try {
            $registeredCheckout = Http::connectTimeout(3)->timeout(8)->withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
            ])->post(env('API_REGISTERCHECKOUT'), [
                'in_item' => $item,
                'in_motivo' => $codeReason,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio externo no disponible al registrar salida.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return $this->temporaryFailure('LEAVE_REGISTRATION_RESULT_UNKNOWN', false);
        }

        if (!$registeredCheckout->successful()) {
            Log::warning('El servicio externo rechazó el registro de salida.', [
                'user_id' => $request->user()->user_id,
                'http_status' => $registeredCheckout->status(),
            ]);

            return $this->temporaryFailure(
                'LEAVE_REGISTRATION_FAILED',
                $registeredCheckout->serverError() || $registeredCheckout->status() === 429,
            );
        }

        $this->userRepository->registerLeave($request->user()->user_id, $reason_premise_id);

        try {
            Redis::del('leave-ticket:' . $leaveTicket);
        } catch (Throwable $exception) {
            Log::warning('No se pudo invalidar un ticket de salida consumido.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);
        }

        return response()->json([
            'status' => 0,
            'message' => 'Salida temporal registrada correctamente',
        ], 200);
    }

    private function temporaryFailure(string $code, bool $retryable = true)
    {
        return response()->json([
            'status' => 1,
            'code' => $code,
            'message' => $retryable
                ? 'Un servicio necesario no está disponible temporalmente. Intente nuevamente en unos segundos.'
                : 'No se pudo confirmar el resultado de la operación. Consulte el estado antes de volver a intentarla.',
            'retryable' => $retryable,
        ], 502);
    }
}
