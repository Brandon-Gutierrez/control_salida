<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error esperado de la API (regla de negocio incumplida o dependencia caída)
 * que se responde como JSON. Los servicios lo lanzan y Laravel lo convierte en
 * respuesta, así los controladores solo describen el camino feliz.
 *
 * Hay dos formatos de cuerpo, heredados de los clientes:
 *  - numérico (`status: 1`): salidas, QR, panel de administración.
 *  - textual (`status: "ERROR"`): inicio de sesión.
 */
class ApiException extends RuntimeException
{
    private const TEMPORARY_FAILURE_HTTP_STATUS = 502;

    private function __construct(private array $body, private int $httpStatus, private array $headers = [])
    {
        parent::__construct($body['message'] ?? 'Error de API.');
    }

    /** Código del error (`code` del cuerpo), si lo tiene. */
    public function errorCode(): ?string
    {
        return $this->body['code'] ?? null;
    }

    /** Demasiados intentos: 429 con el tiempo de espera (cabecera `Retry-After` y `retry_after`). */
    public static function tooManyAttempts(int $retryAfterSeconds, string $code): self
    {
        $retryAfterSeconds = max(1, $retryAfterSeconds);

        return new self(
            [
                'status' => 'ERROR',
                'code' => $code,
                'message' => "Demasiados intentos. Intente nuevamente en {$retryAfterSeconds} segundos.",
                'retry_after' => $retryAfterSeconds,
            ],
            429,
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }

    /** Error con el formato numérico (`status: 1`). */
    public static function failure(int $httpStatus, string $message, ?string $code = null, ?bool $retryable = null): self
    {
        return new self(
            ['status' => 1]
                + ($code !== null ? ['code' => $code] : [])
                + ['message' => $message]
                + ($retryable !== null ? ['retryable' => $retryable] : []),
            $httpStatus,
        );
    }

    /** Error con el formato textual (`status: "ERROR"`). */
    public static function authFailure(int $httpStatus, string $message, ?string $code = null): self
    {
        return new self(
            ['status' => 'ERROR']
                + ($code !== null ? ['code' => $code] : [])
                + ['message' => $message],
            $httpStatus,
        );
    }

    /** Error con un cuerpo ya armado (p. ej. el rechazo por límite de salidas). */
    public static function withBody(int $httpStatus, array $body): self
    {
        return new self($body, $httpStatus);
    }

    /** Un servicio necesario no respondió: `retryable` indica si conviene reintentar. */
    public static function temporaryFailure(string $code, bool $retryable = true): self
    {
        return self::failure(
            self::TEMPORARY_FAILURE_HTTP_STATUS,
            $retryable
                ? 'Un servicio necesario no está disponible temporalmente. Intente nuevamente en unos segundos.'
                : 'No se pudo confirmar el resultado de la operación. Consulte el estado antes de volver a intentarla.',
            $code,
            $retryable,
        );
    }

    /** El servicio externo respondió con error: se reintenta solo si fue 5xx o 429. */
    public static function fromFailedResponse(Response $response, string $code): self
    {
        return self::temporaryFailure($code, $response->serverError() || $response->status() === 429);
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body, $this->httpStatus, $this->headers);
    }
}
