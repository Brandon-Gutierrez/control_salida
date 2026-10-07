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
            ->get(['leave_time', 'return_time']);

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
}
