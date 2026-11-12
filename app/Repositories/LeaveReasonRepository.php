<?php

namespace App\Repositories;

use App\Exceptions\ApiException;
use App\Models\LeaveReason;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveReasonRepository
{
    /** Nombres de los motivos activos, ordenados alfabéticamente. */
    public function allNames(): Collection
    {
        return LeaveReason::orderBy('name')->pluck('name');
    }

    public function findIdByName(string $name): ?int
    {
        return LeaveReason::where('name', $name)->value('reason_id');
    }

    public function findCodeByName(string $name): ?string
    {
        return LeaveReason::where('name', $name)->value('code');
    }

    /**
     * Aplica el catálogo completo que entregó el sistema externo.
     *
     * - Motivo nuevo: se crea.
     * - Motivo existente con otro nombre: se actualiza.
     * - Motivo retirado antes y que vuelve a llegar: se reactiva.
     * - Motivo activo que ya no llega: se desactiva (borrado lógico). Deja de
     *   ofrecerse, pero el historial lo sigue mostrando.
     *
     * Si desactivaría más de la mitad de los motivos activos se cancela sin
     * cambiar nada (una respuesta parcial no puede vaciar el catálogo), salvo
     * que `$force` lo permita.
     *
     * @param  array<int, array{code: string, name: string}>  $items  Catálogo ya validado.
     * @return array{added: int, updated: int, reactivated: int, deactivated: int}
     *
     * @throws ApiException si la sincronización desactivaría demasiados motivos.
     */
    public function applyCatalog(array $items, bool $force = false): array
    {
        return DB::transaction(function () use ($items, $force) {
            $existing = LeaveReason::withTrashed()->get()->keyBy('code');
            $incoming = collect($items)->keyBy('code');

            $active = $existing->reject(fn (LeaveReason $reason) => $reason->trashed());
            $toDeactivate = $active->diffKeys($incoming);

            if (! $force && $active->isNotEmpty() && $toDeactivate->count() > $active->count() * 0.5) {
                throw ApiException::failure(
                    422,
                    sprintf(
                        'La sincronización desactivaría %d de %d motivos activos (más de la mitad). Se canceló para proteger el catálogo: revise la respuesta de Comteco.',
                        $toDeactivate->count(),
                        $active->count(),
                    ),
                    'REASONS_SYNC_TOO_DESTRUCTIVE',
                    false,
                );
            }

            $result = ['added' => 0, 'updated' => 0, 'reactivated' => 0, 'deactivated' => 0];

            foreach ($incoming as $code => $item) {
                $reason = $existing->get($code);

                if ($reason === null) {
                    LeaveReason::create(['code' => $code, 'name' => $item['name']]);
                    $result['added']++;

                    continue;
                }

                if ($reason->trashed()) {
                    $reason->restore();
                    $result['reactivated']++;
                }

                if ($reason->name !== $item['name']) {
                    $reason->update(['name' => $item['name']]);
                    $result['updated']++;
                }
            }

            foreach ($toDeactivate as $reason) {
                $reason->delete();
                $result['deactivated']++;
            }

            return $result;
        });
    }
}
