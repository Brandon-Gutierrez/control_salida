<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\RecordRepository;
use App\Services\External\CheckoutStatus;
use App\Services\External\EmployeeProfileService;
use App\Services\External\ExternalApiService;

/** Datos de la persona y si está fuera en este momento, para la pantalla principal de la app móvil. */
class LeaveStatusService
{
    public function __construct(
        private ExternalApiService $api,
        private EmployeeProfileService $profiles,
        private LeaveStatsService $stats,
        private RecordRepository $records,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException si el sistema externo no responde o no reconoce a la persona.
     */
    public function forUser(User $user): array
    {
        $checkout = $this->api->checkout($user->item, now()->format('Y-m-d'));
        if ($checkout->failed()) {
            throw $this->externalError();
        }

        $employee = $this->api->employee($user->item);
        if ($employee->failed() || $employee->json('status') == 1) {
            throw $this->externalError();
        }

        $status = CheckoutStatus::fromResponse($checkout);
        if ($status->isInvalid()) {
            throw $this->externalError();
        }

        // Sin salida, o con todas sus salidas de hoy ya con retorno: está dentro.
        if (! $status->hasOpenLeave()) {
            return [
                'name' => $employee['name'],
                'item' => $employee['item'],
                'isLeave' => false,
                ...$this->profileExtras($user),
            ];
        }

        return [
            'name' => $employee['name'],
            'item' => $employee['item'],
            'isLeave' => true,
            'dateLeave' => $status->leftAt()?->toIso8601String(),
            // El motivo no viene del sistema externo: se toma del registro
            // local guardado al confirmar la salida.
            'reason' => $this->records->lastReasonName($user->user_id),
            ...$this->profileExtras($user),
        ];
    }

    /** Rol, foto y cargo (sistema externo) y estadísticas de salidas (base local). */
    public function profileExtras(User $user): array
    {
        $profile = $this->profiles->forUser($user);

        return [
            'role' => $user->role?->name,
            'photo_url' => $profile['photo_url'],
            'job_title' => $profile['job_title'],
            'area' => $profile['area'],
            'stats' => $this->stats->forUser($user),
        ];
    }

    private function externalError(): ApiException
    {
        return ApiException::failure(400, 'Error externo');
    }
}
