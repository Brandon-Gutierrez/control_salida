<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\Premise;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe en la bitácora de auditoría. Un fallo al escribir nunca interrumpe
 * la operación que se está auditando: se deja constancia en el log de la app.
 */
class AuditLogger
{
    // Cambios administrativos.
    public const ROLE_CHANGED = 'role.changed';

    public const MANAGER_CREATED = 'manager.created';

    public const MANAGER_PREMISE_CHANGED = 'manager.premise_changed';

    public const PASSWORD_CHANGED = 'manager.password_changed';

    public const DEVICE_RESET = 'device.reset';

    // Incidentes con el sistema externo (Comteco).
    public const LEAVE_RESULT_UNKNOWN = 'leave.result_unknown';

    public const RETURN_RESULT_UNKNOWN = 'return.result_unknown';

    public const LEAVE_LOCAL_WRITE_FAILED = 'leave.local_write_failed';

    public const RETURN_LOCAL_WRITE_FAILED = 'return.local_write_failed';

    public const FOREIGN_OPEN_LEAVE = 'leave.foreign_open_in_external';

    /** Claves que nunca se guardan en el detalle, aunque alguien las pase. */
    private const FORBIDDEN_KEYS = ['password', 'contrasena', 'clave', 'carnet', 'documento', 'in_documento', 'token', 'external_identifier'];

    /**
     * @param  User|null  $actor  Quien hizo el cambio (null: tarea del sistema o consola).
     * @param  User|Premise|null  $subject  Sobre quién o qué se hizo.
     * @param  array<string, mixed>  $details  Datos del cambio (sin secretos).
     */
    public function record(string $event, ?User $actor = null, User|Premise|null $subject = null, array $details = []): void
    {
        try {
            // Una transacción anidada es un savepoint: si el INSERT falla, solo se revierte este.
            DB::transaction(fn () => AuditLog::create([
                'event' => $event,
                'actor_user_id' => $actor?->user_id,
                'actor_name' => $actor?->name,
                'subject_type' => match (true) {
                    $subject instanceof User => 'user',
                    $subject instanceof Premise => 'premise',
                    default => null,
                },
                'subject_id' => $subject?->getKey(),
                'subject_name' => $subject?->name,
                'details' => $details === [] ? null : $this->withoutSecrets($details),
                // Solo hay IP si el cambio vino de una petición de la API (no de un comando).
                'ip_address' => request()->route() !== null ? request()->ip() : null,
            ]));
        } catch (Throwable $exception) {
            Log::error('No se pudo escribir en la bitácora de auditoría.', [
                'event' => $event,
                'actor_user_id' => $actor?->user_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** @param  array<string, mixed>  $details */
    private function withoutSecrets(array $details): array
    {
        $clean = [];
        foreach ($details as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->withoutSecrets($value) : $value;
        }

        return $clean;
    }
}
