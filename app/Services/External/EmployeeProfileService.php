<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Foto y cargo de una persona, tal como los entrega el servicio de terceros
 * (getEmployee). Es solo informativo: si el servicio falla, la app sigue
 * funcionando sin foto ni cargo.
 */
class EmployeeProfileService
{
    private const CACHE_SECONDS = 600;

    /** @return array{photo_url: ?string, job_title: ?string, area: ?string, company: ?string} */
    public function forUser(User $user): array
    {
        $empty = ['photo_url' => null, 'job_title' => null, 'area' => null, 'company' => null];

        // Las cuentas locales (responsables) no existen en el servicio externo.
        if ($user->username !== null || !$user->item) {
            return $empty;
        }

        // Caché en archivos: el almacén "database" de este proyecto no tiene tabla.
        $profile = Cache::store('file')->remember(
            "employee-profile:{$user->item}",
            self::CACHE_SECONDS,
            fn () => $this->fetch((int) $user->item) ?? $empty,
        );

        return $profile;
    }
    // Consulta los datos externos.
    private function fetch(int $item): ?array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)
                ->withHeaders(['keysoftware' => env('KEY_SOFTWARE')])
                ->get(env('API_GETEMPLOYEE'), ['item' => $item]);
        } catch (ConnectionException|Throwable) {
            return null;
        }

        if ($response->failed() || $response->json('status') == 1) {
            return null;
        }

        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $photo = $clean($response->json('photo'));

        return [
            // Solo se entregan URLs web: la app las carga directamente.
            'photo_url' => $photo !== null && preg_match('#^https?://#i', $photo) ? $photo : null,
            'job_title' => $clean($response->json('rolName')),
            'area' => $clean($response->json('area')),
            'company' => $clean($response->json('company')),
        ];
    }
}
