<?php

namespace App\Repositories;

use App\Models\LeaveReason;
use App\Models\ReasonPremise;
use Illuminate\Support\Collection;

class ReasonPremiseRepository
{
    /** Nombres de los motivos habilitados en el predio, ordenados alfabéticamente. */
    public function reasonNamesForPremise(int $premiseId): Collection
    {
        $reasonIds = ReasonPremise::where('premise_id', $premiseId)->pluck('reason_id');

        return LeaveReason::whereIn('reason_id', $reasonIds)
            ->orderBy('name')
            ->pluck('name');
    }

    /** Identificador del motivo habilitado en el predio (fila de la tabla pivote). */
    public function findId(int $premiseId, int $reasonId): ?int
    {
        return ReasonPremise::where([
            'premise_id' => $premiseId,
            'reason_id' => $reasonId,
        ])->value('id');
    }
}
