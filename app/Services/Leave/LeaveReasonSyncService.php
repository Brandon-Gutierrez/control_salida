<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\Setting;
use App\Models\User;
use App\Repositories\LeaveReasonRepository;
use App\Services\External\ExternalApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza el catálogo local de motivos de salida con el de Comteco.
 *
 * Solo cambia el catálogo si la respuesta completa es válida; si no, conserva
 * el vigente. Los motivos que Comteco retira se desactivan (siguen en el
 * historial). Se evita que dos sincronizaciones corran a la vez y se guarda el
 * último resultado para mostrarlo en el panel.
 */
class LeaveReasonSyncService
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SCHEDULED = 'scheduled';

    public const SOURCE_CONSOLE = 'console';

    private const LOCK_KEY = 'reasons-sync';

    private const LOCK_SECONDS = 120;

    private const STATUS_KEY = 'reasons_sync_status';

    public function __construct(
        private ExternalApiService $api,
        private LeaveReasonRepository $reasons,
    ) {}

    /**
     * @return array{added: int, updated: int, reactivated: int, deactivated: int}
     *
     * @throws ApiException si ya hay una sincronización en curso, Comteco no entregó un
     *                      catálogo válido o la sincronización sería demasiado destructiva.
     */
    public function sync(string $source = self::SOURCE_MANUAL, ?User $actor = null, bool $force = false): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw ApiException::failure(409, 'Ya hay una sincronización de motivos en curso.', 'REASONS_SYNC_IN_PROGRESS', true);
        }

        try {
            try {
                $result = $this->reasons->applyCatalog($this->fetchCatalog(), $force);
            } catch (ApiException $exception) {
                $this->recordFailure($source, $actor, $exception->getMessage());

                throw $exception;
            } catch (Throwable $exception) {
                Log::error('Error inesperado al sincronizar los motivos de salida.', [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $this->recordFailure($source, $actor, 'Error inesperado al sincronizar los motivos de salida.');

                throw ApiException::failure(500, 'Error inesperado al sincronizar los motivos de salida.', 'REASONS_SYNC_FAILED', true);
            }

            $this->recordSuccess($source, $actor, $result);
            Log::info('Motivos de salida sincronizados.', $result + ['source' => $source]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /**
     * Último resultado guardado (null si nunca se sincronizó).
     *
     * @return array{status: string, source: string, at: string, actor: ?string, added: ?int, updated: ?int, reactivated: ?int, deactivated: ?int, error: ?string, last_success_at: ?string}|null
     */
    public function status(): ?array
    {
        $decoded = json_decode(Setting::get(self::STATUS_KEY, '') ?? '', true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Ya hubo una sincronización correcta hoy (hora de Bolivia). */
    public function hasSucceededToday(): bool
    {
        $last = $this->status()['last_success_at'] ?? null;

        return $last !== null && Carbon::parse($last)->setTimezone(config('app.timezone'))->isToday();
    }

    /**
     * Catálogo de Comteco validado: lista no vacía, cada motivo con código y
     * descripción, sin códigos repetidos.
     *
     * @return array<int, array{code: string, name: string}>
     */
    private function fetchCatalog(): array
    {
        try {
            $response = $this->api->reasons();
        } catch (ConnectionException) {
            throw ApiException::failure(502, 'No se pudo conectar con Comteco para obtener los motivos de salida.', 'REASONS_SERVICE_UNAVAILABLE', true);
        }

        $error = $response->json('error');
        if ($response->failed() || ! is_array($response->json('data')) || (is_numeric($error) && (float) $error !== 0.0)) {
            throw ApiException::failure(400, 'Error al obtener los motivos de salida');
        }

        $items = [];
        foreach ($response->json('data') as $row) {
            $code = is_array($row) && isset($row['codigo']) && is_scalar($row['codigo']) ? trim((string) $row['codigo']) : '';
            $name = is_array($row) && isset($row['descripcion']) && is_scalar($row['descripcion']) ? trim((string) $row['descripcion']) : '';

            if ($code === '' || $name === '' || mb_strlen($code) > 255 || mb_strlen($name) > 255) {
                throw $this->invalid('Comteco devolvió motivos sin código o sin descripción');
            }
            if (isset($items[$code])) {
                throw $this->invalid('Comteco devolvió códigos de motivo repetidos');
            }

            $items[$code] = ['code' => $code, 'name' => $name];
        }

        if ($items === []) {
            throw $this->invalid('Comteco devolvió una lista de motivos vacía');
        }

        return array_values($items);
    }

    private function invalid(string $reason): ApiException
    {
        return ApiException::failure(422, $reason.'; se conserva el catálogo actual.', 'REASONS_RESPONSE_INVALID', false);
    }

    /** @param  array{added: int, updated: int, reactivated: int, deactivated: int}  $result */
    private function recordSuccess(string $source, ?User $actor, array $result): void
    {
        $now = now()->toIso8601String();

        $this->save([
            'status' => 'success',
            'source' => $source,
            'at' => $now,
            'actor' => $actor?->name,
            'error' => null,
            'last_success_at' => $now,
        ] + $result);
    }

    private function recordFailure(string $source, ?User $actor, string $error): void
    {
        $this->save([
            'status' => 'failed',
            'source' => $source,
            'at' => now()->toIso8601String(),
            'actor' => $actor?->name,
            'added' => null,
            'updated' => null,
            'reactivated' => null,
            'deactivated' => null,
            'error' => $error,
            // Una falla no borra cuándo fue la última sincronización correcta.
            'last_success_at' => $this->status()['last_success_at'] ?? null,
        ]);
    }

    /** @param  array<string, mixed>  $status */
    private function save(array $status): void
    {
        Setting::set(self::STATUS_KEY, json_encode($status));
    }
}
