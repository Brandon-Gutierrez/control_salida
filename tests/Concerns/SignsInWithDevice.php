<?php

namespace Tests\Concerns;

use App\Http\Middleware\CheckActiveSession;
use App\Models\User;
use App\Models\UserDevice;
use App\Support\ClientPlatform;
use Illuminate\Testing\TestResponse;

/** Sesiones de prueba que cumplen las reglas de aplicación y dispositivo. */
trait SignsInWithDevice
{
    protected function deviceIdFor(string $name): string
    {
        return str_pad("device-{$name}", 32, '0');
    }

    /**
     * Autentica a $user como si hubiera iniciado sesión desde su dispositivo
     * vinculado en $platform (por defecto, la primera aplicación que permite su rol).
     */
    protected function signIn(User $user, ?string $platform = null): static
    {
        $platform ??= ClientPlatform::forRole($user->role?->name)[0] ?? ClientPlatform::WEB;
        $deviceId = $this->deviceIdFor("{$user->user_id}-{$platform}");

        UserDevice::updateOrCreate(
            ['user_id' => $user->user_id, 'platform' => $platform],
            ['device_hash' => hash('sha256', $deviceId), 'bound_at' => now()],
        );

        return $this->withoutMiddleware(CheckActiveSession::class)
            ->actingAs($user, 'web')
            ->withSession([ClientPlatform::SESSION_KEY => $platform])
            ->withHeader('DeviceId', $deviceId);
    }

    protected function login(string $username, string $password, string $platform = ClientPlatform::WEB, ?string $deviceId = null): TestResponse
    {
        return $this->withHeaders([
            ClientPlatform::HEADER => $platform,
            'DeviceId' => $deviceId ?? $this->deviceIdFor("default-{$platform}"),
        ])->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
    }
}
