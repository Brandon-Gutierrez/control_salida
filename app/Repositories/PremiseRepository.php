<?php

namespace App\Repositories;

use App\Models\Premise;
use App\Models\ReasonLeave;
use App\Models\Role;
use Illuminate\Support\Collection;

// Consulta y transforma predios.
class PremiseRepository
{
    // Obtiene el identificador del predio.
    public function getPremiseId(string $name): ?int
    {
        return Premise::where('name', $name)->value('premise_id');
    }

    // Lista los predios con sus motivos.
    public function getAllWithReasons(): Collection
    {
        return Premise::query()
            ->select('premise_id', 'name', 'latitude', 'longitude', 'created_at')
            ->with(['responsibleUsers' => fn ($q) => $q->select('user_id', 'name', 'premise_id')
                    ->whereHas('role', fn ($r) => $r->whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE])),
                // Calcula el valor solicitado.
                'leaves' => function ($query) {
                $query->select('reasons.reason_id', 'reasons.name')
                    ->orderBy('reasons.name');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (Premise $premise) => $this->toResource($premise));
    }

    //Reemplaza los motivos de un predio a partir de sus nombres
    public function syncReasonsByName(Premise $premise, array $reasonNames): void
    {
        $reasonIds = ReasonLeave::whereIn('name', $reasonNames)->pluck('reason_id');
        $premise->leaves()->sync($reasonIds);
        $premise->unsetRelation('leaves');
    }

    // Convierte el predio a recurso.
    public function toResource(Premise $premise): array
    {
        return [
            'id' => $premise->premise_id,
            'name' => $premise->name,
            'latitude' => $premise->latitude,
            'longitude' => $premise->longitude,
            'manager' => $this->managerOf($premise),
            'reason_names' => $premise->leaves->sortBy('name')->pluck('name')->values()->all(),
        ];
    }

    /** Responsable asignado (rol MANAGE_PREMISE) o null. */
    private function managerOf(Premise $premise): ?array
    {
        $manager = $premise->relationLoaded('responsibleUsers')
            ? $premise->responsibleUsers->first()
            : $premise->responsibleUsers()
                ->whereHas('role', fn ($r) => $r->whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE]))
                ->first(['user_id', 'name', 'premise_id']);

        return $manager ? ['user_id' => $manager->user_id, 'name' => $manager->name] : null;
    }
}
