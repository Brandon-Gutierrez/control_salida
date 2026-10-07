<?php

namespace App\Services\Qr;

use App\Exceptions\ApiException;
use App\Models\Premise;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/** Emite los tokens temporales con los que cada predio genera su QR. */
class QrTokenService
{
    /**
     * El valor visible del QR es configurable por administración
     * (QrSettingsService). Redis conserva el token esta gracia adicional, que
     * no se muestra: es también la ventana que tiene la persona para elegir el
     * motivo si escanea justo en el último segundo de vida del QR (ver
     * LeaveTicketService::TTL_SECONDS).
     */
    private const GRACE_PERIOD_SECONDS = 30;

    public function __construct(private QrSettingsService $settings) {}

    /**
     * Genera y guarda un token nuevo para el predio.
     *
     * @return array{status: int, token: string, TTL: int, expires_at: string, premise: array}
     *
     * @throws ApiException si Redis no pudo guardar o confirmar el token.
     */
    public function issue(Premise $premise): array
    {
        $premiseId = $premise->premise_id;
        $token = $premise->name.'+'.Str::uuid();
        $visibleTtl = $this->settings->ttlSeconds();
        $issuedAt = now();

        try {
            Redis::setex($token, $visibleTtl + self::GRACE_PERIOD_SECONDS, $premiseId);
            $stored = Redis::get($token);
            $remainingTtl = (int) Redis::ttl($token);
        } catch (Throwable $exception) {
            Log::error('No se pudo guardar el token QR en Redis.', [
                'premise_id' => $premiseId,
                'exception' => $exception::class,
            ]);

            throw ApiException::failure(
                503,
                'No se pudo generar el QR temporal. Intente nuevamente en unos segundos.',
                'QR_SERVICE_UNAVAILABLE',
                true,
            );
        }

        $visibleRemainingTtl = max(0, $remainingTtl - self::GRACE_PERIOD_SECONDS);
        if ($stored === false || $stored === null || (int) $stored !== $premiseId || $visibleRemainingTtl < 1) {
            Log::error('Redis no confirmó el token QR recién generado.', ['premise_id' => $premiseId]);

            throw ApiException::failure(
                503,
                'No se pudo confirmar la generación del QR. Intente nuevamente.',
                'QR_STORE_FAILED',
                true,
            );
        }

        Log::info('Token QR temporal generado.', [
            'premise_id' => $premiseId,
            'qr_fingerprint' => hash('sha256', $token),
            'visible_ttl_seconds' => $visibleRemainingTtl,
            'redis_ttl_seconds' => $remainingTtl,
            'grace_period_seconds' => self::GRACE_PERIOD_SECONDS,
        ]);

        return [
            'status' => 0,
            'token' => $token,
            'TTL' => $visibleRemainingTtl,
            'expires_at' => $issuedAt->copy()->addSeconds($visibleTtl)->toIso8601String(),
            'premise' => ['premise_id' => $premiseId, 'name' => $premise->name],
        ];
    }

    /**
     * Predio al que pertenece un token vigente, o null si venció o no existe.
     *
     * @throws Throwable si Redis no responde.
     */
    public function findPremiseId(string $token): ?int
    {
        $premiseId = Redis::get($token);

        return ($premiseId === false || $premiseId === null || $premiseId === '') ? null : (int) $premiseId;
    }
}
