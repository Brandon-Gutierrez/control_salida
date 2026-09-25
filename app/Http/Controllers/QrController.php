<?php
namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Repositories\UserRepository;
use App\Repositories\RecordRepository;
use App\Models\Premise;
use App\Services\LeaveQuotaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class QrController extends Controller
{
    private const QR_TTL_SECONDS = 300;
    private const QR_GRACE_PERIOD_SECONDS = 30;

    protected UserRepository $userRepository;
    protected RecordRepository $recordRepository;
    protected LeaveQuotaService $leaveQuotaService;

    public function __construct(UserRepository $userRepository, RecordRepository $recordRepository, LeaveQuotaService $leaveQuotaService)
    {
        $this->userRepository = $userRepository;
        $this->recordRepository = $recordRepository;
        $this->leaveQuotaService = $leaveQuotaService;
    }

    //Genera codigos unicos para cada predio, para la generacion de qr y la identificacion del predio
    public function store(Premise $premise)
    {
        $premiseName = $premise->name;
        $premiseId = $premise->premise_id;

        $token = $premiseName . '+' . Str::uuid();
        $visibleTtl = self::QR_TTL_SECONDS;
        $redisTtl = $visibleTtl + self::QR_GRACE_PERIOD_SECONDS;
        $issuedAt = now();

        try {
            // Redis mantiene el token durante la ventana visible y una gracia
            // interna adicional que no se expone al usuario.
            Redis::setex($token, $redisTtl, $premiseId);
            $isStorage = Redis::get($token);
            $remainingTtl = (int) Redis::ttl($token);
        } catch (Throwable $exception) {
            Log::error('No se pudo guardar el token QR en Redis.', [
                'premise_id' => $premiseId,
                'exception' => $exception::class,
            ]);

            return response()->json([
                'status' => 1,
                'code' => 'QR_SERVICE_UNAVAILABLE',
                'message' => 'No se pudo generar el QR temporal. Intente nuevamente en unos segundos.',
                'retryable' => true,
            ], 503);
        }

        $visibleRemainingTtl = max(0, $remainingTtl - self::QR_GRACE_PERIOD_SECONDS);
        if ($isStorage === false || $isStorage === null || (int) $isStorage !== $premiseId || $visibleRemainingTtl < 1) {
            Log::error('Redis no confirmó el token QR recién generado.', ['premise_id' => $premiseId]);

            return response()->json([
                'status' => 1,
                'code' => 'QR_STORE_FAILED',
                'message' => 'No se pudo confirmar la generación del QR. Intente nuevamente.',
                'retryable' => true,
            ], 503);
        }

        Log::info('Token QR temporal generado.', [
            'premise_id' => $premiseId,
            'qr_fingerprint' => hash('sha256', $token),
            'visible_ttl_seconds' => $visibleRemainingTtl,
            'redis_ttl_seconds' => $remainingTtl,
            'grace_period_seconds' => self::QR_GRACE_PERIOD_SECONDS,
        ]);

        return response()->json([
            'status' => 0,
            'token' => $token,
            'TTL' => $visibleRemainingTtl,
            'expires_at' => $issuedAt->copy()->addSeconds($visibleTtl)->toIso8601String(),
            'premise' => ['premise_id' => $premiseId, 'name' => $premiseName],
            ], 200);
    }

    /** Genera el QR únicamente para el predio asignado al responsable autenticado. */
    public function storeForResponsible(Request $request)
    {
        $premise = $request->user()->premise;
        if (!$premise) {
            return response()->json([
                'status' => 1,
                'message' => 'La cuenta no tiene un predio asignado.'
            ], 403);
        }

        return $this->store($premise);
    }

    //Validar si retorna al mismo predio
    public function scan(Request $request)
    {
        $data = $request->validate([
            'qrData' => ['required', 'string'],
        ]);
        $qrData = trim($data['qrData']);
        if ($qrData === '') {
            return response()->json([
                'status' => 1,
                'code' => 'QR_EMPTY',
                'message' => 'No se recibió contenido del código QR.',
                'retryable' => false,
            ], 422);
        }

        $item = $request->user()->item;
        try {
            $qrStatus = Redis::get($qrData);
        } catch (Throwable $exception) {
            Log::error('No se pudo validar el token QR en Redis.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return $this->temporaryFailure('QR_SERVICE_UNAVAILABLE');
        }

        //Validar en redis
        if ($qrStatus === false || $qrStatus === null || $qrStatus === '') {
            Log::info('Token QR inexistente o vencido al escanear.', [
                'user_id' => $request->user()->user_id,
                'qr_fingerprint' => hash('sha256', $qrData),
            ]);

            return response()->json([
                'status' => 1,
                'code' => 'QR_EXPIRED_OR_INVALID',
                'message' => 'Este QR venció o no es válido. Solicite uno actualizado y vuelva a escanear.',
                'retryable' => false,
            ], 410);
        }
        
        try {
            $userData = Http::connectTimeout(3)->timeout(8)->withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETEMPLOYEE'), ['item' => $item]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio de empleados no disponible durante el escaneo QR.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return $this->temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE');
        }

        //Si el usuario no esta identificado
        if ($userData->failed()) {
            Log::warning('El servicio de empleados respondió con error durante el escaneo QR.', [
                'user_id' => $request->user()->user_id,
                'http_status' => $userData->status(),
            ]);

            return $this->temporaryFailure(
                'EMPLOYEE_SERVICE_UNAVAILABLE',
                $userData->serverError() || $userData->status() === 429,
            );
        }

        if ($userData->json('status') == 1) {
            return response()->json([
                'status' => 1,
                'code' => 'EMPLOYEE_NOT_IDENTIFIED',
                'message' => 'No se pudo identificar al usuario en el sistema. Verifique la cuenta e intente nuevamente.',
                'retryable' => false,
            ], 422);
        }
        //LOGICA CON API
        $date = now()->format('Y-m-d');
        try {
            $dataCheckout = Http::connectTimeout(3)->timeout(8)->withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
            ])->post(env('API_GETCHECKOUT'), [
                'in_item' => $item,
                'in_fecha' => $date,
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio de salidas no disponible durante el escaneo QR.', [
                'user_id' => $request->user()->user_id,
                'exception' => $exception::class,
            ]);

            return $this->temporaryFailure('CHECKOUT_SERVICE_UNAVAILABLE');
        }

        if ($dataCheckout->failed()) {
            Log::warning('El servicio de salidas respondió con error durante el escaneo QR.', [
                'user_id' => $request->user()->user_id,
                'http_status' => $dataCheckout->status(),
            ]);

            return $this->temporaryFailure(
                'CHECKOUT_SERVICE_UNAVAILABLE',
                $dataCheckout->serverError() || $dataCheckout->status() === 429,
            );
        }
        //Si el usuario no salida, mostramos los motivos
        $checkoutError = $dataCheckout->json('error');
        if (is_numeric($checkoutError)
            && (float) $checkoutError === -1.0
            && empty($dataCheckout->json('data'))) {
            $quotaExceeded = $this->leaveQuotaService->check($request->user(), (int) $qrStatus);
            if ($quotaExceeded) {
                return response()->json($this->leaveQuotaService->rejectionPayload($quotaExceeded), 403);
            }

            $leaveTicket = (string) Str::uuid();
            $ticketPayload = json_encode([
                'user_id' => $request->user()->user_id,
                'premise_id' => (int) $qrStatus,
            ]);
            try {
                Redis::setex('leave-ticket:' . $leaveTicket, 300, $ticketPayload);
                $ticketTtl = (int) Redis::ttl('leave-ticket:' . $leaveTicket);
            } catch (Throwable $exception) {
                Log::error('No se pudo crear el ticket de confirmación de salida.', [
                    'user_id' => $request->user()->user_id,
                    'exception' => $exception::class,
                ]);

                return $this->temporaryFailure('LEAVE_TICKET_UNAVAILABLE');
            }

            if ($ticketTtl < 1) {
                return $this->temporaryFailure('LEAVE_TICKET_UNAVAILABLE');
            }

            $ticketExpiresAt = now()->addSeconds($ticketTtl)->toIso8601String();

            return response()->json([
                'status' => 0,
                'action' => 'showReasons',
                // qrData remains as a backward-compatible alias for existing clients.
                'qrData' => $leaveTicket,
                'leaveTicket' => $leaveTicket,
                'leaveTicketTTL' => $ticketTtl,
                'leaveTicketExpiresAt' => $ticketExpiresAt,
                'message' => 'QR escaneado correctamente',
            ], 200);
        }

        $checkoutData = $dataCheckout->json('data');
        if (!is_array($checkoutData) || empty($checkoutData[0]['id_solicitud'])) {
            Log::warning('Respuesta no reconocida del servicio de salidas durante escaneo QR.', [
                'user_id' => $request->user()->user_id,
            ]);

            return $this->temporaryFailure('CHECKOUT_RESPONSE_INVALID');
        }
        // Sino se marca el retorno.
            $isSamePremise = $this->recordRepository->isSamePremise(
                $request->user()->user_id, (int) $qrStatus);
            if (!$isSamePremise)
            {
                return response()->json([
                    "status" => 1,
                    "code" => "RETURN_PREMISE_MISMATCH",
                    "message" => "El predio de retorno es diferente al predio de salida"
                ], 403);
            }
            try {
                $registeredReturn = Http::connectTimeout(3)->timeout(8)->withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
            ])->post(env('API_REGISTERCHECKOUT'), [
                'in_item' => $item,
                'in_id_solicitud' => $checkoutData[0]['id_solicitud'],
            //'in_fecha' => now()->format('Y-m-d H:i:s'),
            ]);
            } catch (ConnectionException $exception) {
                Log::warning('No se pudo confirmar el retorno en el servicio externo.', [
                    'user_id' => $request->user()->user_id,
                    'exception' => $exception::class,
                ]);

                return $this->temporaryFailure('RETURN_RESULT_UNKNOWN', false);
            }
            if ($registeredReturn->successful())
            {
                // Cierra el registro local (usado para mostrar el motivo de la
                // última salida en el resumen del usuario).
                $this->userRepository->registerReturn($request->user()->user_id);

                return response()->json([
                    'status' => 0,
                    'action' => 'showHome',
                    'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                    ], 200);
            }
            Log::warning('El servicio externo rechazó el registro de retorno.', [
                'user_id' => $request->user()->user_id,
                'http_status' => $registeredReturn->status(),
            ]);

        return $this->temporaryFailure(
            'RETURN_REGISTRATION_FAILED',
            $registeredReturn->serverError() || $registeredReturn->status() === 429,
        );
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
    //LOGICA CON BASE DE DATOS LOCAL
        /*
        $userId = $this->userRepository->getUserId($item);
        //Verifica si el usuario tiene un registro de salida sin retorno
        $isLeave = $this->userRepository->isUserLeave($userId);
        
        //Si el usuario no tiene retorno, actualizamos el retorno
        if($isLeave)
        {
            $premiseName = str($qrData)->before('+');
            $isSamePremise = $this->recordRepository->isSamePremise($premiseName);
            if($isSamePremise == false)
            {
                return response()->json([
                    "status" => 1,
                    "message" => "El predio de retorno es diferente al predio de salida"
                ], 403);
            }

            $this->userRepository->registerReturn($userId);

            return response()->json([
                'status' => 0,
                'action' => 'showHome',
                'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                ], 200);
        }
        //Sino se muestran los motivos de salida
        return response()->json([
            'status' => 0, 
            'action' => 'showReasons', 
            'qrData' => $qrData,
            'message' => 'QR escaneado correctamente'
            ], 200);
    }
    */
}
