<?php

namespace App\Repositories;

use App\Models\Record;

class RecordRepository
{
    public function registerLeave(int $userId, int $reasonPremiseId): Record
    {
        return Record::create([
            'leave_time' => now(),
            'return_time' => null,
            'user_id' => $userId,
            'reason_premise_id' => $reasonPremiseId,
        ]);
    }

    /** Cierra las salidas abiertas de la persona; devuelve cuántas cerró. */
    public function registerReturn(int $userId): int
    {
        return Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->update(['return_time' => now()]);
    }

    /** La salida abierta más reciente de la persona fue desde este predio. */
    public function isOpenLeaveFromPremise(int $userId, int $premiseId): bool
    {
        $record = Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->with('reasonPremise')
            ->latest('leave_time')
            ->first();

        return $record?->reasonPremise?->premise_id === $premiseId;
    }

    /**
     * Nombre del motivo de la última salida registrada. Se desempata por
     * record_id porque leave_time se guarda con precisión de segundos y dos
     * salidas podrían registrarse en el mismo segundo.
     */
    public function lastReasonName(int $userId): ?string
    {
        return Record::where('user_id', $userId)
            ->orderByDesc('leave_time')
            ->orderByDesc('record_id')
            ->with('reasonPremise.reason')
            ->first()
            ?->reasonPremise
            ?->reason
            ?->name;
    }
}
