<?php

namespace App\Repositories;

use App\Models\Premise;
use App\Models\ReasonLeave;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PremiseRepository
{
    public function getPremiseId(string $name): ?int
    {
        return Premise::where('name', $name)->value('id');
    }

    public function getAllWithReasons(): Collection
    {
        return Premise::query()
            ->select('id', 'name', 'created_at')
            ->with(['leaves' => function ($query) {
                $query->select('reason_leaves.id', 'reason_leaves.name')
                    ->orderBy('reason_leaves.name');
            }])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Premise $premise) {
                return [
                    'id' => $premise->id,
                    'name' => $premise->name,
                    'reason_names' => $premise->leaves?->pluck('name')->values()->all() ?? [],
                ];
            });
    }

}