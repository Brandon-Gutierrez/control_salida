<?php

namespace App\Repositories;

use App\Models\LeaveReason;
use App\Models\Premise;
use App\Models\ReasonPremise;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PremiseRepository
{
    public function create(array $attributes): Premise
    {
        return Premise::create($attributes);
    }

    public function update(Premise $premise, array $attributes): void
    {
        $premise->update($attributes);
    }

    public function findNameById(int $premiseId): ?string
    {
        return Premise::where('premise_id', $premiseId)->value('name');
    }

    public function findIdByName(string $name): ?int
    {
        return Premise::where('name', $name)->value('premise_id');
    }

    /** Predios (los más recientes primero) con su responsable y sus motivos. */
    public function allWithReasons(): Collection
    {
        return Premise::query()
            ->select('premise_id', 'name', 'latitude', 'longitude', 'created_at')
            ->with([
                'managers' => fn ($managers) => $managers->select('user_id', 'name', 'premise_id'),
                'reasons' => fn ($reasons) => $reasons->select('reasons.reason_id', 'reasons.name')
                    ->orderBy('reasons.name'),
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Reemplaza los motivos del predio por los indicados (por nombre).
     *
     * Las relaciones motivo-predio nunca se borran: las salidas registradas
     * apuntan a ellas. Un motivo retirado se marca con `deleted_at` y, si se
     * vuelve a habilitar, se restaura la misma fila.
     */
    public function syncReasonsByName(Premise $premise, array $reasonNames): void
    {
        $wanted = LeaveReason::whereIn('name', $reasonNames)->pluck('reason_id');

        DB::transaction(function () use ($premise, $wanted) {
            $existing = ReasonPremise::withTrashed()
                ->where('premise_id', $premise->premise_id)
                ->get()
                ->keyBy('reason_id');

            foreach ($existing as $reasonId => $relation) {
                $keep = $wanted->contains($reasonId);

                if ($keep && $relation->trashed()) {
                    $relation->restore();
                } elseif (! $keep && ! $relation->trashed()) {
                    $relation->delete();
                }
            }

            foreach ($wanted->diff($existing->keys()) as $reasonId) {
                ReasonPremise::create(['reason_id' => $reasonId, 'premise_id' => $premise->premise_id]);
            }
        });

        $premise->unsetRelation('reasons');
    }
}
