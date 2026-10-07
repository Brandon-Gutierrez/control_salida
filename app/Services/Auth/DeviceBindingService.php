<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Models\UserDevice;
use App\Repositories\UserActiveSessionRepository;

/** Vincula cada cuenta a un único dispositivo por aplicación (web / mobile). */
class DeviceBindingService
{
    public const MIN_ID_LENGTH = 16;

    public const MAX_ID_LENGTH = 255;

    public function __construct(private UserActiveSessionRepository $sessions) {}

    public static function isValidDeviceId(mixed $deviceId): bool
    {
        return is_string($deviceId)
            && strlen($deviceId) >= self::MIN_ID_LENGTH
            && strlen($deviceId) <= self::MAX_ID_LENGTH;
    }

    /**
     * El primer inicio de sesión en una aplicación vincula el dispositivo; los
     * siguientes solo se aceptan desde ese mismo dispositivo. createOrFirst
     * evita que dos primeros inicios simultáneos vinculen dos dispositivos.
     */
    public function bindOrVerify(User $user, string $platform, string $deviceId): bool
    {
        $hash = hash('sha256', $deviceId);
        $device = UserDevice::createOrFirst(
            ['user_id' => $user->user_id, 'platform' => $platform],
            ['device_hash' => $hash, 'bound_at' => now()],
        );

        return hash_equals($device->device_hash, $hash);
    }

    /** El dispositivo recibido es el vinculado a la cuenta en esa aplicación. */
    public function matches(User $user, string $platform, mixed $deviceId): bool
    {
        if (! self::isValidDeviceId($deviceId)) {
            return false;
        }

        $storedHash = UserDevice::where('user_id', $user->user_id)
            ->where('platform', $platform)
            ->value('device_hash');

        return $storedHash !== null && hash_equals($storedHash, hash('sha256', $deviceId));
    }

    /**
     * Quita el dispositivo autorizado y cierra las sesiones de esa aplicación,
     * para que la cuenta pueda vincular un dispositivo nuevo.
     *
     * @return bool true si había un dispositivo vinculado.
     */
    public function reset(User $user, string $platform): bool
    {
        $deleted = UserDevice::where('user_id', $user->user_id)
            ->where('platform', $platform)
            ->delete();

        $this->sessions->deleteForPlatform($user->user_id, $platform);

        return $deleted > 0;
    }
}
