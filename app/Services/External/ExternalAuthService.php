<?php

namespace App\Services\External;

/** Autentica credenciales contra el sistema externo de personal. */
class ExternalAuthService
{
    public function __construct(private ExternalApiService $api) {}

    /**
     * @return array{external_identifier: mixed, name: mixed, item: mixed}|null
     *                                                                          null si las credenciales no son válidas o el servicio falla.
     */
    public function authenticate(string $username, string $password): ?array
    {
        $response = $this->api->login($username, $password);

        if ($response->failed() || $response->json('status') == 1) {
            return null;
        }

        return [
            'external_identifier' => $response->json('token'),
            'name' => $response->json('name'),
            'item' => $response->json('item'),
        ];
    }
}
