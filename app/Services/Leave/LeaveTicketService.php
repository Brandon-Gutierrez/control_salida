<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Comprobante que recibe la persona al escanear un QR de salida y que debe
 * presentar al confirmar el motivo. Ata el escaneo a una cuenta y a un predio
 * durante una ventana corta.
 */
class LeaveTicketService
{
    private const KEY_PREFIX = 'leave-ticket:';

    /**
     * Ventana visible para elegir el motivo tras escanear: igual a la gracia
     * del QR. Se le suma una gracia interna adicional (invisible) para que un
     * envío justo en el límite no falle por la latencia de la red.
     */
    private const TTL_SECONDS = 30;

    private const GRACE_PERIOD_SECONDS = 10;

    /**
     * Crea el comprobante de la persona para el predio escaneado.
     *
     * @return array{ticket: string, ttl: int, expires_at: string}
     *
     * @throws ApiException si Redis no pudo guardarlo.
     */
    public function issue(User $user, int $premiseId): array
    {
        $ticket = (string) Str::uuid();
        $key = self::KEY_PREFIX.$ticket;
        $payload = json_encode([
            'user_id' => $user->user_id,
            'premise_id' => $premiseId,
        ]);

        try {
            Redis::setex($key, self::TTL_SECONDS + self::GRACE_PERIOD_SECONDS, $payload);
            $remainingTtl = (int) Redis::ttl($key);
        } catch (Throwable $exception) {
            Log::error('No se pudo crear el ticket de confirmación de salida.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::temporaryFailure('LEAVE_TICKET_UNAVAILABLE');
        }

        $visibleTtl = max(0, $remainingTtl - self::GRACE_PERIOD_SECONDS);
        if ($visibleTtl < 1) {
            throw ApiException::temporaryFailure('LEAVE_TICKET_UNAVAILABLE');
        }

        return [
            'ticket' => $ticket,
            'ttl' => $visibleTtl,
            'expires_at' => now()->addSeconds($visibleTtl)->toIso8601String(),
        ];
    }

    /**
     * Predio al que pertenece un comprobante vigente de la persona.
     *
     * @throws ApiException si Redis falla, el comprobante venció o es de otra cuenta.
     */
    public function resolvePremiseId(User $user, string $ticket): int
    {
        try {
            $stored = Redis::get(self::KEY_PREFIX.$ticket);
        } catch (Throwable $exception) {
            Log::error('No se pudo validar el ticket de salida en Redis.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            throw ApiException::failure(
                503,
                'No se pudo validar el escaneo por un problema temporal. Intente nuevamente.',
                'QR_SERVICE_UNAVAILABLE',
                true,
            );
        }

        $data = is_string($stored) ? json_decode($stored, true) : null;
        if (! is_array($data) || ! isset($data['user_id'], $data['premise_id'])) {
            throw ApiException::failure(
                410,
                'El comprobante del escaneo venció. Vuelva a escanear el QR si sigue vigente; de lo contrario, solicite uno nuevo.',
                'LEAVE_TICKET_EXPIRED',
                false,
            );
        }

        if ((int) $data['user_id'] !== (int) $user->user_id) {
            throw ApiException::failure(
                403,
                'El escaneo no pertenece a esta cuenta. Vuelva a escanear el QR.',
                'LEAVE_TICKET_INVALID',
                false,
            );
        }

        return (int) $data['premise_id'];
    }

    /** Invalida un comprobante ya consumido; si Redis falla solo se registra. */
    public function discard(User $user, string $ticket): void
    {
        try {
            Redis::del(self::KEY_PREFIX.$ticket);
        } catch (Throwable $exception) {
            Log::warning('No se pudo invalidar un ticket de salida consumido.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
