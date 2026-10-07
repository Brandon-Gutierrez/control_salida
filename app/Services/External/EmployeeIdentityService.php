<?php

namespace App\Services\External;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/** Confirma en el sistema externo que la cuenta corresponde a un empleado vigente. */
class EmployeeIdentityService
{
    public function __construct(private ExternalApiService $api) {}

    /**
     * @param  string  $context  Momento en que se verifica, para el registro de errores
     *                           (p. ej. "durante el escaneo QR").
     *
     * @throws ApiException si el servicio no responde o no reconoce a la persona.
     */
    public function assertIdentified(User $user, string $context): void
    {
        try {
            $response = $this->api->fast()->employee($user->item);
        } catch (ConnectionException $exception) {
            Log::warning("Servicio de empleados no disponible {$context}.", [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('EMPLOYEE_SERVICE_UNAVAILABLE');
        }

        if ($response->failed()) {
            Log::warning("El servicio de empleados respondió con error {$context}.", [
                'user_id' => $user->user_id,
                'http_status' => $response->status(),
            ]);

            throw ApiException::fromFailedResponse($response, 'EMPLOYEE_SERVICE_UNAVAILABLE');
        }

        if ($response->json('status') == 1) {
            throw ApiException::failure(
                422,
                'No se pudo identificar al usuario en el sistema. Verifique la cuenta e intente nuevamente.',
                'EMPLOYEE_NOT_IDENTIFIED',
                false,
            );
        }
    }
}
