<?php

namespace App\Services\External;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;

/** Autentica credenciales contra el sistema externo de personal. */
class ExternalAuthService
{
    public function __construct(private ExternalApiService $api) {}

    /**
     * @return array{external_identifier: mixed, name: mixed, item: mixed}|null
     *                                                                          null si las credenciales no son válidas.
     *
     * @throws ApiException 503 si Comteco no responde (no es un error de credenciales).
     */
    public function authenticate(string $username, string $password): ?array
    {
        try {
            $response = $this->api->login($username, $password);
        } catch (ConnectionException) {
            throw $this->unavailable();
        }

        // Una caída o saturación de Comteco no es una contraseña incorrecta.
        if ($response->serverError() || $response->status() === 429) {
            throw $this->unavailable();
        }

        if ($response->failed() || $response->json('status') == 1) {
            return null;
        }

        return [
            'external_identifier' => $response->json('token'),
            'name' => $response->json('name'),
            'item' => $response->json('item'),
        ];
    }

    private function unavailable(): ApiException
    {
        return ApiException::authFailure(
            503,
            'No se pudo conectar con el sistema de personal de Comteco. Intente nuevamente en unos minutos.',
            'AUTH_SERVICE_UNAVAILABLE',
        );
    }

    /**
     * Valida a una persona por item y número de documento (carnet) con el
     * servicio `validaId`. Responde `{error: 0, data: {item, documento, nombre}}`
     * si coinciden o `{error: 1, message}` si no.
     *
     * @return array{external_identifier: null, name: mixed, item: mixed}|null
     *                                                                         null si item y documento no coinciden o el servicio rechaza la petición.
     */
    public function authenticateByCarnet(string $item, string $carnet): ?array
    {
        $response = $this->api->fast()->loginWithCarnet($item, $carnet);

        if ($response->failed() || (int) $response->json('error') !== 0 || ! is_array($response->json('data'))) {
            return null;
        }

        return [
            // Este servicio no entrega un token; la persona se identifica por su item.
            'external_identifier' => null,
            'name' => $response->json('data.nombre'),
            'item' => $response->json('data.item') ?? $item,
        ];
    }
}
