<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\LeaveReasonRepository;
use App\Repositories\PremiseRepository;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\RecordRepository;
use App\Services\External\EmployeeIdentityService;
use App\Services\External\ExternalApiService;
use Illuminate\Http\Client\ConnectionException;
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
    ) {}

    /**
     * @param  string  $leaveTicket  Comprobante recibido al escanear el QR.
     *
     * @throws ApiException si alguna regla o servicio impide registrar la salida.
     */
    public function register(User $user, string $leaveTicket, string $premiseName, string $reasonName): void
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

        $this->employees->assertIdentified($user, self::CONTEXT);

        $reasonPremiseId = $this->findReasonPremiseId($premiseId, $premiseName, $reasonName);

        $this->limits->assertWithinLimit($user, $premiseId);

        $this->registerInExternalApi($user, $reasonName);

        $this->records->registerLeave($user->user_id, $reasonPremiseId);

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

    private function registerInExternalApi(User $user, string $reasonName): void
    {
        try {
            $registered = $this->api->fast()->registerCheckout([
                'in_item' => $user->item,
                'in_motivo' => $this->reasons->findCodeByName($reasonName),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('Servicio externo no disponible al registrar salida.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
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
    }
}
