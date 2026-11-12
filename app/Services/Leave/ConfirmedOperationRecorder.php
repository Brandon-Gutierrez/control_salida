<?php

namespace App\Services\Leave;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe en la base local una operación que Comteco YA aceptó.
 *
 * Si la escritura local falla se reintenta de inmediato; si sigue fallando no
 * se repite nada contra Comteco (ya quedó registrado ahí) y se deja constancia
 * completa en el log y en la bitácora para repararlo a mano.
 */
class ConfirmedOperationRecorder
{
    public const ATTEMPTS = 3;

    /** Pausa entre intentos (µs); las pruebas la ponen en 0. */
    public static int $pauseMicroseconds = 100_000;

    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  string  $auditEvent  Evento de bitácora si no se pudo escribir.
     * @param  array<string, mixed>  $context  Datos de la operación (sin secretos).
     * @param  Closure(): mixed  $write
     * @return bool true si se escribió en la base local.
     */
    public function persist(string $auditEvent, User $user, array $context, Closure $write): bool
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            try {
                $write();

                return true;
            } catch (Throwable $exception) {
                $lastError = $exception;
                Log::warning('Falló la escritura local de una operación ya aceptada por Comteco.', [
                    'user_id' => $user->user_id,
                    'attempt' => $attempt,
                    'exception' => $exception::class,
                ]);
                if ($attempt < self::ATTEMPTS && self::$pauseMicroseconds > 0) {
                    usleep(self::$pauseMicroseconds);
                }
            }
        }

        Log::critical('Comteco aceptó la operación pero no se pudo guardar en la base local.', $context + [
            'user_id' => $user->user_id,
            'user_item' => $user->item,
            'event' => $auditEvent,
            'exception' => $lastError ? $lastError::class : null,
            'message' => $lastError?->getMessage(),
        ]);
        $this->audit->record($auditEvent, null, $user, $context + [
            'error' => $lastError ? $lastError::class : null,
        ]);

        return false;
    }
}
