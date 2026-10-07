<?php

namespace App\Services\External;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Puerta de entrada única al sistema externo de personal.
 *
 * Solo arma y envía las peticiones; interpretar las respuestas es
 * responsabilidad de quien las usa. Sin tiempos de espera explícitos se aplican
 * los del cliente HTTP; `fast()` / `withTimeouts()` devuelven una copia con
 * tiempos acotados para los flujos que atiende una persona en pantalla.
 */
class ExternalApiService
{
    private const FAST_CONNECT_TIMEOUT_SECONDS = 3;

    private const FAST_TIMEOUT_SECONDS = 8;

    private ?int $connectTimeout = null;

    private ?int $timeout = null;

    public function withTimeouts(int $connectTimeoutSeconds, int $timeoutSeconds): static
    {
        $copy = clone $this;
        $copy->connectTimeout = $connectTimeoutSeconds;
        $copy->timeout = $timeoutSeconds;

        return $copy;
    }

    public function fast(): static
    {
        return $this->withTimeouts(self::FAST_CONNECT_TIMEOUT_SECONDS, self::FAST_TIMEOUT_SECONDS);
    }

    public function login(string $username, string $password): Response
    {
        return $this->request()->post(config('services.external_api.login_url'), [
            'username' => $username,
            'password' => $password,
        ]);
    }

    public function employee(int|string $item): Response
    {
        return $this->request()->get(config('services.external_api.employee_url'), ['item' => $item]);
    }

    public function reasons(): Response
    {
        return $this->request()->post(config('services.external_api.reasons_url'), [
            'in_Entidad' => 'MOTIVO_SALIDA',
            'in_nombre_maq' => '',
        ]);
    }

    /** Salida registrada del empleado en la fecha (Y-m-d). */
    public function checkout(int|string $item, string $date): Response
    {
        return $this->request()->post(config('services.external_api.checkout_url'), [
            'in_item' => $item,
            'in_fecha' => $date,
        ]);
    }

    /** Registra una salida (`in_motivo`) o un retorno (`in_id_solicitud`). */
    public function registerCheckout(array $payload): Response
    {
        return $this->request()->post(config('services.external_api.register_checkout_url'), $payload);
    }

    private function request(): PendingRequest
    {
        $request = Http::withHeaders(['keysoftware' => config('services.external_api.key')]);

        if ($this->connectTimeout !== null) {
            $request = $request->connectTimeout($this->connectTimeout);
        }
        if ($this->timeout !== null) {
            $request = $request->timeout($this->timeout);
        }

        return $request;
    }
}
