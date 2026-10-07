<?php

namespace Tests\Concerns;

use App\Http\Middleware\EnsureActiveSession;
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
        $platform ??= $this->firstPlatformFor($user);
        $deviceId = $this->deviceIdFor("{$user->user_id}-{$platform}");

        UserDevice::updateOrCreate(
            ['user_id' => $user->user_id, 'platform' => $platform],
            ['device_hash' => hash('sha256', $deviceId), 'bound_at' => now()],
        );

        return $this->withoutMiddleware(EnsureActiveSession::class)
            ->actingAs($user, 'web')
            ->withSession([ClientPlatform::SESSION_KEY => $platform])
            ->withHeader('DeviceId', $deviceId);
    }

    /** Primera aplicación que permite el rol de la cuenta (web si ninguna). */
    private function firstPlatformFor(User $user): string
    {
        foreach (ClientPlatform::ALL as $platform) {
            if (ClientPlatform::allows($platform, $user->role?->name)) {
                return $platform;
            }
        }

        return ClientPlatform::WEB;
    }

    protected function login(string $username, string $password, string $platform = ClientPlatform::WEB, ?string $deviceId = null): TestResponse
    {
        return $this->withHeaders([
            ClientPlatform::HEADER => $platform,
            'DeviceId' => $deviceId ?? $this->deviceIdFor("default-{$platform}"),
        ])->postJson('/api/auth/login', ['username' => $username, 'password' => $password]);
    }
}
