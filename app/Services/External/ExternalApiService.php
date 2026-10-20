<?php

namespace App\Services\External;

use Carbon\Carbon;
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
    /** Campos de la respuesta de registro que pueden traer la fecha y hora completas, por prioridad. */
    private const TIMESTAMP_KEYS = ['fecha_registro', 'fecha_hora', 'fecha_retorno', 'fecha_salida', 'fechahora', 'datetime', 'created_at', 'date'];

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

    /** Valida a una persona por su item y carnet (ingreso manual en el predio). */
    public function loginWithCarnet(string $item, string $carnet): Response
    {
        return $this->request()->post(config('services.external_api.login_carnet_url'), [
            'in_item' => $item,
            'in_documento' => $carnet,
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

    /** Nombre del predio en texto plano (mayúsculas, sin espacios sobrantes), como lo espera `in_predio`. */
    public static function premiseLabel(string $premiseName): string
    {
        return mb_strtoupper(trim($premiseName));
    }

    /**
     * Fecha y hora con que el sistema externo registró la salida o el retorno,
     * tomada de su respuesta, para guardar la misma hora en la base local y no
     * generar desfases entre ambas. Null si la respuesta no la trae.
     */
    public static function registeredAt(Response $response): ?Carbon
    {
        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        $values = [];
        array_walk_recursive($body, function ($value, $key) use (&$values) {
            if (is_string($key) && is_scalar($value) && (string) $value !== '' && ! isset($values[strtolower($key)])) {
                $values[strtolower($key)] = (string) $value;
            }
        });

        foreach (self::TIMESTAMP_KEYS as $key) {
            if (isset($values[$key]) && ($parsed = self::parseTimestamp($values[$key]))) {
                return $parsed;
            }
        }

        // Fecha y hora en campos separados.
        $date = $values['fecha'] ?? null;
        $time = $values['hora'] ?? null;
        if ($date !== null && $time !== null) {
            return self::parseTimestamp(trim($date.' '.$time));
        }

        return null;
    }

    private static function parseTimestamp(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
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
