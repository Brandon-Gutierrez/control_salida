<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DeviceBindingService;
use App\Support\ClientPlatform;
use Illuminate\Console\Command;

/**
 * Para TI: desvincula el dispositivo de una cuenta cuando nadie puede hacerlo
 * desde el panel (p. ej. el único administrador cambió de equipo).
 */
class ResetUserDevice extends Command
{
    protected $signature = 'devices:reset
        {user : user_id, item o usuario local de la cuenta}
        {--platform=all : web, mobile o all}';

    protected $description = 'Desvincula el dispositivo autorizado de una cuenta para que pueda usar uno nuevo';

    public function handle(DeviceBindingService $devices): int
    {
        $key = (string) $this->argument('user');
        $user = User::where('username', $key)
            ->orWhere(fn ($q) => ctype_digit($key)
                ? $q->where('user_id', (int) $key)->orWhere('item', (int) $key)
                : $q->whereRaw('1 = 0'))
            ->first();

        if (!$user) {
            $this->error("No se encontró la cuenta \"{$key}\".");
            return self::FAILURE;
        }

        $option = strtolower((string) $this->option('platform'));
        $platforms = $option === 'all' ? ClientPlatform::ALL : [$option];
        if (array_diff($platforms, ClientPlatform::ALL)) {
            $this->error('La opción --platform debe ser web, mobile o all.');
            return self::FAILURE;
        }

        foreach ($platforms as $platform) {
            $hadDevice = $devices->reset($user, $platform);
            $this->line(sprintf(
                '%s (%s): %s',
                $user->name,
                $platform,
                $hadDevice ? 'dispositivo desvinculado y sesiones cerradas.' : 'no tenía dispositivo vinculado.',
            ));
        }

        return self::SUCCESS;
    }
}
