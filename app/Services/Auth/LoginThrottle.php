<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Log;

/**
 * Límite de intentos fallidos de inicio de sesión, para proteger las
 * credenciales de Comteco y las contraseñas de los responsables:
 *
 * - {@see self::MAX_PER_ACCOUNT} fallos por cuenta + IP en {@see self::WINDOW_SECONDS} segundos;
 * - {@see self::MAX_PER_IP} fallos por IP en el mismo lapso (cualquier cuenta).
 *
 * Solo cuentan las credenciales incorrectas: una caída de Comteco o un error
 * de dispositivo no consumen intentos. Un inicio de sesión correcto reinicia el
 * contador de esa cuenta.
 */
class LoginThrottle
{
    public const MAX_PER_ACCOUNT = 5;

    public const MAX_PER_IP = 20;

    public const WINDOW_SECONDS = 60;

    public function __construct(private RateLimiter $limiter) {}

    /** @throws ApiException 429 con el tiempo de espera si se superó algún límite. */
    public function assertAllowed(string $username, ?string $ip): void
    {
        foreach ([[$this->accountKey($username, $ip), self::MAX_PER_ACCOUNT], [$this->ipKey($ip), self::MAX_PER_IP]] as [$key, $max]) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                Log::warning('Inicio de sesión bloqueado por exceso de intentos.', ['ip' => $ip]);

                throw ApiException::tooManyAttempts($this->limiter->availableIn($key), 'LOGIN_THROTTLED');
            }
        }
    }

    public function recordFailure(string $username, ?string $ip): void
    {
        $this->limiter->hit($this->accountKey($username, $ip), self::WINDOW_SECONDS);
        $this->limiter->hit($this->ipKey($ip), self::WINDOW_SECONDS);
    }

    /** Un inicio de sesión correcto libera a esa cuenta (el contador por IP sigue su curso). */
    public function clear(string $username, ?string $ip): void
    {
        $this->limiter->clear($this->accountKey($username, $ip));
    }

    // La clave no guarda el usuario ni la IP en claro.
    private function accountKey(string $username, ?string $ip): string
    {
        return 'login:account:'.sha1(mb_strtolower(trim($username)).'|'.$ip);
    }

    private function ipKey(?string $ip): string
    {
        return 'login:ip:'.sha1((string) $ip);
    }
}
