<?php

namespace App\Repositories;

use App\Models\LeaveReason;
use Illuminate\Support\Collection;

class LeaveReasonRepository
{
    /** Nombres de todos los motivos, ordenados alfabéticamente. */
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
     * Crea o actualiza los motivos recibidos del sistema externo.
     *
     * @return array<int, array> Motivos nuevos o modificados.
     */
    public function sync(array $externalReasons): array
    {
        $changed = [];

        foreach ($externalReasons as $externalReason) {
            $reason = LeaveReason::firstOrNew(['code' => $externalReason['codigo']]);
            $reason->name = $externalReason['descripcion'];

            $isChanged = ! $reason->exists || $reason->isDirty();
            $reason->save();

            if ($isChanged) {
                $changed[] = $reason->toArray();
            }
        }

        return $changed;
    }
}
