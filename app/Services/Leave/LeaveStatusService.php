<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\RecordRepository;
use App\Services\External\EmployeeProfileService;
use App\Services\External\ExternalApiService;
use Illuminate\Support\Carbon;

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

        if ($checkout->json('error') === -1 && empty($checkout->json('data'))) {
            return [
                'name' => $employee['name'],
                'item' => $employee['item'],
                'token' => $employee->json('token'),
                'isLeave' => false,
                ...$this->profileExtras($user),
            ];
        }

        $data = $checkout->json('data');
        $leftAt = $data[0]['fecha_salida']
            ? Carbon::createFromFormat('Y-m-d H:i:s', $data[0]['fecha_salida'])->toIso8601String()
            : null;

        return [
            'name' => $employee['name'],
            'item' => $employee['item'],
            'token' => $employee->json('token'),
            'isLeave' => true,
            'dateLeave' => $leftAt,
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
            'unreturned_leaves' => $this->stats->unreturnedBeforeToday($user),
        ];
    }

    private function externalError(): ApiException
    {
        return ApiException::failure(400, 'Error externo');
    }
}
