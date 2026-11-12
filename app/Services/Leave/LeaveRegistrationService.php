<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\LeaveReasonRepository;
use App\Repositories\PremiseRepository;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\RecordRepository;
use App\Services\Audit\AuditLogger;
use App\Services\Location\PremiseLocationService;
use App\Services\External\EmployeeIdentityService;
use App\Services\External\ExternalApiService;
use App\Services\External\RegistrationOutcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/** Confirma la salida de una persona que ya escaneó el QR de un predio y eligió su motivo. */
class LeaveRegistrationService
{
    private const CONTEXT = 'al registrar salida';

    public function __construct(
        private LeaveTicketService $tickets,
        private EmployeeIdentityService $employees,
        private LeaveReasonRepository $reasons,
        private PremiseRepository $premises,
        private ReasonPremiseRepository $reasonPremises,
        private LeaveLimitService $limits,
        private ExternalApiService $api,
        private RecordRepository $records,
        private ConfirmedOperationRecorder $recorder,
        private AuditLogger $audit,
        private PremiseLocationService $locations,
    ) {}

    /**
     * @param  string  $leaveTicket  Comprobante recibido al escanear el QR.
     * @param  array{latitude: float, longitude: float, accuracy_m: float}|null  $location  Prueba de ubicación ya validada,
     *                                                                                        que debe corresponder al predio del comprobante. Solo es
     *                                                                                        null en el ingreso manual (lo opera el responsable en el predio).
     *
     * @throws ApiException si alguna regla o servicio impide registrar la salida.
     */
    public function register(User $user, string $leaveTicket, string $premiseName, string $reasonName, ?array $location): void
    {
        if ($leaveTicket === '') {
            throw ApiException::failure(
                422,
                'Falta el comprobante del escaneo. Vuelva a escanear el QR.',
                'LEAVE_TICKET_INVALID',
                false,
            );
        }

        $premiseId = $this->tickets->resolvePremiseId($user, $leaveTicket);

        // Al confirmar, la persona sigue debiendo estar en el predio ligado al comprobante.
        if ($location !== null) {
            $this->locations->assertWithinPremise($location, $premiseId);
        }

        $this->employees->assertIdentified($user, self::CONTEXT);

        $reasonPremiseId = $this->findReasonPremiseId($premiseId, $premiseName, $reasonName);

        $this->limits->assertWithinLimit($user);

        $registered = $this->registerInExternalApi($user, $reasonName, $premiseName);

        // La hora de salida es la que registró el sistema externo, no la del
        // servidor, para que ambas bases coincidan.
        $leftAt = ExternalApiService::registeredAt($registered);
        if ($leftAt === null) {
            Log::warning('El servicio externo no devolvió la fecha de la salida; se usa la hora del servidor.', [
                'user_id' => $user->user_id,
            ]);
        }

        // Comteco ya aceptó la salida: si falla la escritura local se reintenta
        // y, si sigue fallando, queda en la bitácora (nunca se reenvía a Comteco).
        $this->recorder->persist(
            AuditLogger::LEAVE_LOCAL_WRITE_FAILED,
            $user,
            [
                'premise_id' => $premiseId,
                'premise' => $premiseName,
                'reason' => $reasonName,
                'left_at' => $leftAt?->toDateTimeString(),
            ],
            fn () => $this->records->registerLeave($user->user_id, $reasonPremiseId, $leftAt),
        );

        $this->tickets->discard($user, $leaveTicket);
    }

    /** El predio elegido debe ser el del QR escaneado y el motivo debe estar habilitado en él. */
    private function findReasonPremiseId(int $premiseId, string $premiseName, string $reasonName): int
    {
        $requestedPremiseId = $this->premises->findIdByName($premiseName);
        if (! $requestedPremiseId || $premiseId !== (int) $requestedPremiseId) {
            throw ApiException::failure(403, 'El predio seleccionado no coincide con el predio del código QR.');
        }

        $reasonId = $this->reasons->findIdByName($reasonName);
        $reasonPremiseId = $reasonId ? $this->reasonPremises->findId($premiseId, $reasonId) : null;
        if (! $reasonPremiseId) {
            throw ApiException::failure(
                422,
                'El motivo de salida no está disponible para el predio seleccionado.',
                'REASON_NOT_AVAILABLE_FOR_PREMISE',
                false,
            );
        }

        return $reasonPremiseId;
    }

    private function registerInExternalApi(User $user, string $reasonName, string $premiseName): Response
    {
        try {
            $registered = $this->api->fast()->registerCheckout([
                'in_item' => $user->item,
                'in_motivo' => $this->reasons->findCodeByName($reasonName),
                'in_predio' => ExternalApiService::premiseLabel($premiseName),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio externo no disponible al registrar salida.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            // Resultado incierto: no se reenvía solo; queda para revisión manual.
            $this->audit->record(AuditLogger::LEAVE_RESULT_UNKNOWN, null, $user, [
                'premise' => $premiseName,
                'reason' => $reasonName,
            ]);

            throw ApiException::temporaryFailure('LEAVE_REGISTRATION_RESULT_UNKNOWN', false);
        }

        if (! $registered->successful()) {
            Log::warning('El servicio externo rechazó el registro de salida.', [
                'user_id' => $user->user_id,
                'http_status' => $registered->status(),
            ]);

            throw ApiException::fromFailedResponse($registered, 'LEAVE_REGISTRATION_FAILED');
        }

        // El contenido de la respuesta también puede rechazar (error distinto de 0):
        // Comteco decide si acepta la salida (p. ej. ya tiene una hoy).
        $outcome = RegistrationOutcome::fromResponse($registered);
        if ($outcome->rejected()) {
            Log::warning('El servicio externo no aceptó la salida.', [
                'user_id' => $user->user_id,
                'message' => $outcome->message(),
            ]);

            throw ApiException::failure(
                422,
                $outcome->message() ?? 'El sistema externo no aceptó la salida.',
                'LEAVE_REJECTED_BY_EXTERNAL',
                false,
            );
        }

        return $registered;
    }
}
