<?php

namespace App\Services\External;

use Illuminate\Http\Client\Response;

/**
 * Resultado de registrar una salida o un retorno en Comteco, leído del
 * contenido de la respuesta y no solo de su código HTTP.
 *
 * Los servicios de Comteco responden con `error: 0` cuando aceptan y con otro
 * valor (y un `message`) cuando rechazan; el inicio de sesión usa `status: 1`
 * para rechazar. Se reconocen ambas formas. Una respuesta sin ninguna de las
 * dos (p. ej. cuerpo vacío) se acepta como hasta ahora: es el caso a ajustar
 * cuando se tenga el JSON real del registro.
 */
final class RegistrationOutcome
{
    private function __construct(private readonly bool $accepted, private readonly ?string $message) {}

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        if (! is_array($body)) {
            return new self(true, null);
        }

        $error = $body['error'] ?? null;
        $status = $body['status'] ?? null;
        $rejected = (is_numeric($error) && (float) $error !== 0.0)
            || (is_numeric($status) && (float) $status === 1.0);

        $message = $body['message'] ?? null;

        return new self(! $rejected, is_string($message) && trim($message) !== '' ? trim($message) : null);
    }

    public function accepted(): bool
    {
        return $this->accepted;
    }

    public function rejected(): bool
    {
        return ! $this->accepted;
    }

    /** Mensaje de Comteco (útil cuando rechaza). */
    public function message(): ?string
    {
        return $this->message;
    }
}
