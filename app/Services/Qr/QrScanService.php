<?php

namespace App\Services\Qr;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\PremiseRepository;
use App\Repositories\RecordRepository;
use App\Services\Audit\AuditLogger;
use App\Services\External\CheckoutStatus;
use App\Services\External\EmployeeIdentityService;
use App\Services\External\ExternalApiService;
use App\Services\External\RegistrationOutcome;
use App\Services\Leave\ConfirmedOperationRecorder;
use App\Services\Leave\LeaveLimitService;
use App\Services\Leave\LeaveTicketService;
use App\Services\Location\PremiseLocationService;
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
        private PremiseRepository $premises,
        private ConfirmedOperationRecorder $recorder,
        private AuditLogger $audit,
        private PremiseLocationService $locations,
    ) {}

    /**
     * @param  array{latitude: float, longitude: float, accuracy_m: float}  $location  Prueba de ubicación ya validada.
     * @return array<string, mixed> Cuerpo de la respuesta (`action`: showReasons | showHome).
     *
     * @throws ApiException
     */
    public function scan(User $user, string $qrData, array $location): array
    {
        $qrData = trim($qrData);
        if ($qrData === '') {
            throw ApiException::failure(422, 'No se recibió contenido del código QR.', 'QR_EMPTY', false);
        }

        $premiseId = $this->premiseIdOf($user, $qrData);

        // Debe estar cerca del predio de ESTE QR, no de cualquier predio.
        $this->locations->assertWithinPremise($location, $premiseId);

        return $this->scanAtPremise($user, $premiseId);
    }

    /**
     * Lo que ocurre cuando una persona se presenta en un predio, sea por QR o
     * por ingreso manual: salida (comprobante para elegir motivo) o retorno.
     * No comprueba la ubicación: el QR la comprueba en `scan` y el ingreso
     * manual lo opera el responsable desde el propio predio.
     *
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function scanAtPremise(User $user, int $premiseId): array
    {
        $this->employees->assertIdentified($user, self::CONTEXT);

        $status = CheckoutStatus::fromResponse($this->fetchCheckout($user));

        if ($status->isInvalid()) {
            Log::warning('Respuesta no reconocida del servicio de salidas durante escaneo QR.', [
                'user_id' => $user->user_id,
            ]);

            throw ApiException::temporaryFailure('CHECKOUT_RESPONSE_INVALID');
        }

        // Con una salida abierta se registra el retorno; si no (nunca salió o
        // ya volvió), se muestran los motivos y Comteco decide si acepta la salida.
        return $status->hasOpenLeave()
            ? $this->registerReturn($user, $premiseId, $status)
            : $this->startLeave($user, $premiseId);
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
        $this->limits->assertWithinLimit($user);

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

    private function registerReturn(User $user, int $premiseId, CheckoutStatus $status): array
    {
        if (! $this->records->isOpenLeaveFromPremise($user->user_id, $premiseId)) {
            // Comteco dice que está fuera pero aquí no hay ninguna salida abierta:
            // la salida no se hizo desde este sistema. Se deja constancia para revisarla.
            if (! $this->records->hasOpenLeave($user->user_id)) {
                $this->audit->record(AuditLogger::FOREIGN_OPEN_LEAVE, null, $user, [
                    'premise_id' => $premiseId,
                    'premise' => $this->premises->findNameById($premiseId),
                    'request_id' => $status->requestId(),
                    'reason_code' => $status->reasonCode(),
                    'left_at' => $status->leftAt()?->toDateTimeString(),
                ]);
            }

            throw ApiException::failure(
                403,
                'El predio de retorno es diferente al predio de salida',
                'RETURN_PREMISE_MISMATCH',
            );
        }

        $premiseName = $this->premises->findNameById($premiseId) ?? '';

        try {
            $registered = $this->api->fast()->registerCheckout([
                'in_item' => $user->item,
                'in_id_solicitud' => $status->requestId(),
                'in_predio' => ExternalApiService::premiseLabel($premiseName),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('No se pudo confirmar el retorno en el servicio externo.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);
            // Resultado incierto: no se reenvía solo; queda para revisión manual.
            $this->audit->record(AuditLogger::RETURN_RESULT_UNKNOWN, null, $user, [
                'premise_id' => $premiseId,
                'premise' => $premiseName,
                'request_id' => $status->requestId(),
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

        // El contenido de la respuesta también puede rechazar (error distinto de 0).
        $outcome = RegistrationOutcome::fromResponse($registered);
        if ($outcome->rejected()) {
            Log::warning('El servicio externo no aceptó el retorno.', [
                'user_id' => $user->user_id,
                'message' => $outcome->message(),
            ]);

            throw ApiException::failure(
                422,
                $outcome->message() ?? 'El sistema externo no aceptó el retorno.',
                'RETURN_REJECTED_BY_EXTERNAL',
                false,
            );
        }

        // Cierra el registro local (usado para mostrar el motivo de la
        // última salida en el resumen del usuario).
        // Solo se cierra la última salida abierta; las anteriores sin retorno se ignoran.
        // La hora de retorno es la que registró el sistema externo, no la del servidor.
        $returnedAt = ExternalApiService::registeredAt($registered);
        if ($returnedAt === null) {
            Log::warning('El servicio externo no devolvió la fecha del retorno; se usa la hora del servidor.', [
                'user_id' => $user->user_id,
            ]);
        }

        // Comteco ya aceptó el retorno: si falla la escritura local se reintenta
        // y, si sigue fallando, queda en la bitácora (nunca se reenvía a Comteco).
        $closed = null;
        $saved = $this->recorder->persist(
            AuditLogger::RETURN_LOCAL_WRITE_FAILED,
            $user,
            [
                'premise_id' => $premiseId,
                'premise' => $premiseName,
                'request_id' => $status->requestId(),
                'returned_at' => $returnedAt?->toDateTimeString(),
            ],
            function () use ($user, $returnedAt, &$closed) {
                $closed = $this->records->registerReturn($user->user_id, $returnedAt);
            },
        );

        if ($saved && $closed !== 1) {
            Log::warning('El retorno se registró en el servicio externo pero no había una salida local abierta.', [
                'user_id' => $user->user_id,
            ]);
        }

        return [
            'status' => 0,
            'action' => 'showHome',
            'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente',
        ];
    }
}
