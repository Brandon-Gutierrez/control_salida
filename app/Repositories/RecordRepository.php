<?php

namespace App\Repositories;

use App\Models\Record;
use Illuminate\Support\Facades\DB;

class RecordRepository
{
    /**
     * Registra la salida con un id que es siempre el anterior + 1, sin saltos.
     * Una secuencia normal de la base de datos salta números cuando un insert
     * falla o se revierte; aquí el id se calcula dentro de una transacción con
     * la tabla bloqueada, así dos salidas simultáneas no obtienen el mismo número.
     */
    public function registerLeave(int $userId, int $reasonPremiseId): Record
    {
        return DB::transaction(function () use ($userId, $reasonPremiseId) {
            $connection = DB::connection();
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('LOCK TABLE records IN EXCLUSIVE MODE');
            }

            $record = new Record([
                'leave_time' => now(),
                'return_time' => null,
                'user_id' => $userId,
                'reason_premise_id' => $reasonPremiseId,
            ]);
            $record->record_id = ((int) Record::max('record_id')) + 1;
            $record->save();

            // Mantiene la secuencia de la base alineada con el último id usado.
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement(
                    "SELECT setval(pg_get_serial_sequence('records', 'record_id'), ?)",
                    [$record->record_id],
                );
            }

            return $record;
        });
    }

    /** Cierra solo la salida abierta más reciente de la persona; devuelve cuántas cerró (0 o 1). */
    public function registerReturn(int $userId): int
    {
        $record = Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->orderByDesc('leave_time')
            ->orderByDesc('record_id')
            ->first();

        return $record ? (int) $record->update(['return_time' => now()]) : 0;
    }

    /** La salida abierta más reciente de la persona fue desde este predio. */
    public function isOpenLeaveFromPremise(int $userId, int $premiseId): bool
    {
        $record = Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->with('reasonPremise')
            ->orderByDesc('leave_time')
            ->orderByDesc('record_id')
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
