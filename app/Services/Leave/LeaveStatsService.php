<?php

namespace App\Services\Leave;

use App\Models\Record;
use App\Models\User;
use Carbon\Carbon;

/**
 * Cuántas veces salió una persona y cuántos minutos estuvo fuera, según los
 * registros locales, en el día, la semana (lunes a domingo) y el mes
 * calendario en curso (no los últimos 7 o 30 días).
 */
class LeaveStatsService
{
    /** @return array<string, array{from: string, exits: int, minutes: int}> */
    public function forUser(User $user): array
    {
        $now = now();
        $periods = [
            'day' => $now->copy()->startOfDay(),
            'week' => $now->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $now->copy()->startOfMonth(),
        ];

        $records = Record::where('user_id', $user->user_id)
            ->where('leave_time', '>=', min($periods))
            ->get(['leave_time', 'return_time'])
            // Una salida anterior a hoy sin retorno no se cuenta: se avisa aparte.
            ->reject(fn ($record) => $this->isUnreturnedBefore($record, $now->copy()->startOfDay()));

        $stats = [];
        foreach ($periods as $name => $start) {
            $inPeriod = $records->filter(fn ($record) => Carbon::parse($record->leave_time)->gte($start));
            $stats[$name] = [
                'from' => $start->toDateString(),
                'exits' => $inPeriod->count(),
                // Una salida sin retorno cuenta hasta este momento.
                'minutes' => (int) round($inPeriod->sum(function ($record) use ($now) {
                    $end = $record->return_time ? Carbon::parse($record->return_time) : $now;

                    return max(0, Carbon::parse($record->leave_time)->diffInSeconds($end, false)) / 60;
                })),
            ];
        }

        return $stats;
    }

    /**
     * Salidas del último mes, anteriores a hoy, que nunca marcaron retorno.
     *
     * @return array<int, array{date: string, reason: ?string}> La más reciente primero.
     */
    public function unreturnedBeforeToday(User $user): array
    {
        return Record::where('user_id', $user->user_id)
            ->whereNull('return_time')
            ->where('leave_time', '<', now()->startOfDay())
            ->where('leave_time', '>=', now()->subMonth())
            ->with('reasonPremise.reason')
            ->orderByDesc('leave_time')
            ->get()
            ->map(fn ($record) => [
                'date' => Carbon::parse($record->leave_time)->toIso8601String(),
                'reason' => $record->reasonPremise?->reason?->name,
            ])
            ->all();
    }

    private function isUnreturnedBefore(Record $record, Carbon $startOfToday): bool
    {
        return $record->return_time === null && Carbon::parse($record->leave_time)->lt($startOfToday);
    }
}
