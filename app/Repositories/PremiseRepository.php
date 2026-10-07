<?php

namespace App\Repositories;

use App\Models\LeaveReason;
use App\Models\Premise;
use Illuminate\Database\Eloquent\Collection;

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

    /** Reemplaza los motivos del predio por los indicados (por nombre). */
    public function syncReasonsByName(Premise $premise, array $reasonNames): void
    {
        $reasonIds = LeaveReason::whereIn('name', $reasonNames)->pluck('reason_id');
        $premise->reasons()->sync($reasonIds);
        $premise->unsetRelation('reasons');
    }
}
