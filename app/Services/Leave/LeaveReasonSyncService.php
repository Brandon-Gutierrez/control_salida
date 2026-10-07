<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Repositories\LeaveReasonRepository;
use App\Services\External\ExternalApiService;

/** Sincroniza el catálogo local de motivos de salida con el del sistema externo. */
class LeaveReasonSyncService
{
    public function __construct(
        private ExternalApiService $api,
        private LeaveReasonRepository $reasons,
    ) {}

    /**
     * @return array<int, array> Motivos nuevos o modificados (vacío si no hubo cambios).
     *
     * @throws ApiException si el sistema externo no entregó el catálogo.
     */
    public function sync(): array
    {
        $response = $this->api->reasons();

        if ($response->failed() || $response->json('data') == null) {
            throw ApiException::failure(400, 'Error al obtener los motivos de salida');
        }

        return $this->reasons->sync($response->json('data'));
    }
}
