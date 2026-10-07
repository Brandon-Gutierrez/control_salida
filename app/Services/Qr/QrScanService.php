<?php

namespace App\Services\Qr;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\RecordRepository;
use App\Services\External\EmployeeIdentityService;
use App\Services\External\ExternalApiService;
use App\Services\Leave\LeaveLimitService;
use App\Services\Leave\LeaveTicketService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Qué ocurre cuando una persona escanea el QR de un predio: si no está fuera
 * recibe un comprobante para elegir el motivo de su salida; si ya está fuera,
 * se registra su retorno.
 */
class QrScanService
{
    private const CONTEXT = 'durante el escaneo QR';

    public function __construct(
        private QrTokenService $qrTokens,
        private LeaveTicketService $tickets,
        private EmployeeIdentityService $employees,
        private ExternalApiService $api,
        private LeaveLimitService $limits,
        private RecordRepository $records,
    ) {}

    /**
     * @return array<string, mixed> Cuerpo de la respuesta (`action`: showReasons | showHome).
     *
     * @throws ApiException
     */
    public function scan(User $user, string $qrData): array
    {
        $qrData = trim($qrData);
        if ($qrData === '') {
            throw ApiException::failure(422, 'No se recibió contenido del código QR.', 'QR_EMPTY', false);
        }

        $premiseId = $this->premiseIdOf($user, $qrData);

        $this->employees->assertIdentified($user, self::CONTEXT);

        $checkout = $this->fetchCheckout($user);

        // Si no hay salida abierta hoy, se muestran los motivos.
        $checkoutError = $checkout->json('error');
        if (is_numeric($checkoutError) && (float) $checkoutError === -1.0 && empty($checkout->json('data'))) {
            return $this->startLeave($user, $premiseId);
        }

        return $this->registerReturn($user, $premiseId, $checkout);
    }

    /** Predio del QR escaneado; falla si el QR venció o Redis no responde. */
    private function premiseIdOf(User $user, string $qrData): int
    {
        try {
            $premiseId = $this->qrTokens->findPremiseId($qrData);
        } catch (Throwable $exception) {
            Log::error('No se pudo validar el token QR en Redis.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('QR_SERVICE_UNAVAILABLE');
        }

        if ($premiseId === null) {
            Log::info('Token QR inexistente o vencido al escanear.', [
                'user_id' => $user->user_id,
                'qr_fingerprint' => hash('sha256', $qrData),
            ]);

            throw ApiException::failure(
                410,
                'Este QR venció o no es válido. Solicite uno actualizado y vuelva a escanear.',
                'QR_EXPIRED_OR_INVALID',
                false,
            );
        }

        return $premiseId;
    }

    private function fetchCheckout(User $user): Response
    {
        try {
            $checkout = $this->api->fast()->checkout($user->item, now()->format('Y-m-d'));
        } catch (ConnectionException $exception) {
            Log::warning('Servicio de salidas no disponible durante el escaneo QR.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('CHECKOUT_SERVICE_UNAVAILABLE');
        }

        if ($checkout->failed()) {
            Log::warning('El servicio de salidas respondió con error durante el escaneo QR.', [
                'user_id' => $user->user_id,
                'http_status' => $checkout->status(),
            ]);

            throw ApiException::fromFailedResponse($checkout, 'CHECKOUT_SERVICE_UNAVAILABLE');
        }

        return $checkout;
    }

    private function startLeave(User $user, int $premiseId): array
    {
        $this->limits->assertWithinLimit($user, $premiseId);

        $ticket = $this->tickets->issue($user, $premiseId);

        return [
            'status' => 0,
            'action' => 'showReasons',
            // qrData remains as a backward-compatible alias for existing clients.
            'qrData' => $ticket['ticket'],
            'leaveTicket' => $ticket['ticket'],
            'leaveTicketTTL' => $ticket['ttl'],
            'leaveTicketExpiresAt' => $ticket['expires_at'],
            'message' => 'QR escaneado correctamente',
        ];
    }

    private function registerReturn(User $user, int $premiseId, Response $checkout): array
    {
        $checkoutData = $checkout->json('data');
        if (! is_array($checkoutData) || empty($checkoutData[0]['id_solicitud'])) {
            Log::warning('Respuesta no reconocida del servicio de salidas durante escaneo QR.', [
                'user_id' => $user->user_id,
            ]);

            throw ApiException::temporaryFailure('CHECKOUT_RESPONSE_INVALID');
        }

        if (! $this->records->isOpenLeaveFromPremise($user->user_id, $premiseId)) {
            throw ApiException::failure(
                403,
                'El predio de retorno es diferente al predio de salida',
                'RETURN_PREMISE_MISMATCH',
            );
        }

        try {
            $registered = $this->api->fast()->registerCheckout([
                'in_item' => $user->item,
                'in_id_solicitud' => $checkoutData[0]['id_solicitud'],
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('No se pudo confirmar el retorno en el servicio externo.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('RETURN_RESULT_UNKNOWN', false);
        }

        if (! $registered->successful()) {
            Log::warning('El servicio externo rechazó el registro de retorno.', [
                'user_id' => $user->user_id,
                'http_status' => $registered->status(),
            ]);

            throw ApiException::fromFailedResponse($registered, 'RETURN_REGISTRATION_FAILED');
        }

        // Cierra el registro local (usado para mostrar el motivo de la
        // última salida en el resumen del usuario).
        $this->records->registerReturn($user->user_id);

        return [
            'status' => 0,
            'action' => 'showHome',
            'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente',
        ];
    }
}
