<?php

namespace App\Repositories;

use App\Models\Premise;
use App\Models\ReasonLeave;
use Illuminate\Support\Collection;

class PremiseRepository
{
    public function getPremiseId(string $name): ?int
    {
        return Premise::where('name', $name)->value('premise_id');
    }

    public function getAllWithReasons(): Collection
    {
        return Premise::query()
            ->select('premise_id', 'name', 'created_at')
            ->with(['leaves' => function ($query) {
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

    public function toResource(Premise $premise): array
    {
        return [
            'id' => $premise->premise_id,
            'name' => $premise->name,
            'reason_names' => $premise->leaves->sortBy('name')->pluck('name')->values()->all(),
        ];
    }
}
