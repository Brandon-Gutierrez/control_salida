<?php

namespace App\Services\External;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;

/**
 * Estado de salida de una persona hoy, según la consulta diaria de Comteco.
 *
 * Formato real:
 *  - con salida: `{"error": 0, "message": "Ok", "data": [{"id_solicitud": "100445",
 *    "nro_item": "2811", "cod_motivo": "VISITA_CLIENTES",
 *    "fecha_salida": "2026-09-09 11:17:06", "fecha_retorno": null}]}`
 *  - sin salida: `{"error": -1, "message": "NO REGISTRO SALIDA", "data": []}`
 *
 * Una solicitud sin `fecha_retorno` sigue abierta (la persona está fuera).
 * Quién puede salir o volver y en qué día lo decide Comteco: aquí solo se
 * interpreta lo que responde.
 */
final class CheckoutStatus
{
    /** No registró ninguna salida. */
    public const NONE = 'none';

    /** Tiene una salida abierta (sin retorno). */
    public const OPEN = 'open';

    /** Tiene salidas, pero todas ya con retorno. */
    public const RETURNED = 'returned';

    /** La respuesta no se reconoce: no se debe decidir nada con ella. */
    public const INVALID = 'invalid';

    /** @param  array<string, mixed>|null  $open  La solicitud abierta. */
    private function __construct(public readonly string $state, private readonly ?array $open = null) {}

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $data = $response->json('data');

        if (! is_array($data)) {
            return new self(self::INVALID);
        }

        $entries = array_values(array_filter($data, 'is_array'));
        $errorCode = is_numeric($error) ? (float) $error : null;

        if ($entries === []) {
            // Solo "sin salida registrada" (-1) con lista vacía cuenta como ausencia.
            return new self($errorCode === -1.0 ? self::NONE : self::INVALID);
        }

        if ($errorCode !== null && $errorCode !== 0.0 && $errorCode !== -1.0) {
            return new self(self::INVALID);
        }

        foreach ($entries as $entry) {
            $returnedAt = $entry['fecha_retorno'] ?? null;
            if ($returnedAt === null || trim((string) $returnedAt) === '') {
                // Una solicitud abierta sin identificador no se puede cerrar.
                return empty($entry['id_solicitud'])
                    ? new self(self::INVALID)
                    : new self(self::OPEN, $entry);
            }
        }

        return new self(self::RETURNED);
    }

    public function hasOpenLeave(): bool
    {
        return $this->state === self::OPEN;
    }

    public function isInvalid(): bool
    {
        return $this->state === self::INVALID;
    }

    /** Identificador de la solicitud abierta, para registrar su retorno. */
    public function requestId(): string|int|null
    {
        return $this->open['id_solicitud'] ?? null;
    }

    /** Código del motivo de la salida abierta (`cod_motivo`). */
    public function reasonCode(): ?string
    {
        $code = $this->open['cod_motivo'] ?? null;

        return $code === null ? null : (string) $code;
    }

    /** Hora de la salida abierta (`fecha_salida`), en la zona horaria de la app. */
    public function leftAt(): ?Carbon
    {
        $value = $this->open['fecha_salida'] ?? null;
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', (string) $value, config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
