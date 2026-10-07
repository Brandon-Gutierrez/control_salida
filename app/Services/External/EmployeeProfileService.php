<?php

namespace App\Services\External;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Foto y cargo de una persona, tal como los entrega el sistema externo
 * (getEmployee). Es solo informativo: si el servicio falla, la app sigue
 * funcionando sin foto ni cargo.
 */
class EmployeeProfileService
{
    private const CACHE_SECONDS = 600;

    private const CONNECT_TIMEOUT_SECONDS = 3;

    private const TIMEOUT_SECONDS = 5;

    public function __construct(private ExternalApiService $api) {}

    /** @return array{photo_url: ?string, job_title: ?string, area: ?string, company: ?string} */
    public function forUser(User $user): array
    {
        $empty = ['photo_url' => null, 'job_title' => null, 'area' => null, 'company' => null];

        // Las cuentas locales (responsables) no existen en el sistema externo.
        if ($user->username !== null || ! $user->item) {
            return $empty;
        }

        // Caché en archivos: el almacén "database" de este proyecto no tiene tabla.
        return Cache::store('file')->remember(
            "employee-profile:{$user->item}",
            self::CACHE_SECONDS,
            fn () => $this->fetch((int) $user->item) ?? $empty,
        );
    }

    private function fetch(int $item): ?array
    {
        try {
            $response = $this->api
                ->withTimeouts(self::CONNECT_TIMEOUT_SECONDS, self::TIMEOUT_SECONDS)
                ->employee($item);
        } catch (Throwable) {
            return null;
        }

        if ($response->failed() || $response->json('status') == 1) {
            return null;
        }

        $clean = fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null;
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
