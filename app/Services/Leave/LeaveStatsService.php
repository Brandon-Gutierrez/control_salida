<?php

namespace App\Services\Leave;

use App\Models\Record;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuántas veces salió una persona y cuántos minutos estuvo fuera, según los
 * registros locales, en el día, la semana (lunes a domingo) y el mes
 * calendario en curso (no los últimos 7 o 30 días).
 *
 * Los totales los calcula la base de datos (COUNT y SUM sobre las salidas de
 * esa persona, con el índice de usuario y fecha): no se traen las filas a PHP,
 * así el costo no crece con el historial.
 */
class LeaveStatsService
{
    /** @return array<string, array{from: string, exits: int, minutes: int}> */
    public function forUser(User $user): array
    {
        $now = now();
        $startOfToday = $now->copy()->startOfDay();
        $periods = [
            'day' => $startOfToday,
            'week' => $now->copy()->startOfWeek(Carbon::MONDAY),
            'month' => $now->copy()->startOfMonth(),
        ];

        $totals = $this->totals($user, $periods, min($periods), null, $now);

        $stats = [];
        foreach ($periods as $name => $start) {
            $stats[$name] = [
                'from' => $start->toDateString(),
                'exits' => $totals[$name]['exits'],
                'minutes' => $totals[$name]['minutes'],
            ];
        }

        return $stats;
    }

    /**
     * Salidas y minutos fuera entre dos fechas (ambas incluidas). Igual que en
     * `forUser`, una salida anterior a hoy sin retorno no se cuenta.
     *
     * @return array{from: string, to: string, exits: int, minutes: int, unreturned_leaves: array}
     */
    public function forRange(User $user, Carbon $from, Carbon $to): array
    {
        $now = now();
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        $totals = $this->totals($user, ['range' => $start], $start, $end, $now)['range'];

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'exits' => $totals['exits'],
            'minutes' => $totals['minutes'],
            // Solo las del rango; las de hoy se omiten porque aún puede marcar retorno.
            'unreturned_leaves' => $this->unreturnedBeforeToday($user, $start, $end),
        ];
    }

    /**
     * Salidas anteriores a hoy que nunca marcaron retorno dentro del rango
     * indicado (sin rango, las del último mes). La vista de hoy no las pide.
     *
     * @return array<int, array{date: string, reason: ?string}> La más reciente primero.
     */
    public function unreturnedBeforeToday(User $user, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $startOfToday = now()->startOfDay();

        return Record::where('user_id', $user->user_id)
            ->whereNull('return_time')
            ->where('leave_time', '<', $startOfToday)
            ->where('leave_time', '>=', $from ?? now()->subMonth())
            ->when($to, fn ($query) => $query->where('leave_time', '<=', $to))
            ->with('reasonPremise.reason')
            ->orderByDesc('leave_time')
            ->get()
            ->map(fn ($record) => [
                'date' => Carbon::parse($record->leave_time)->toIso8601String(),
                'reason' => $record->reasonPremise?->reason?->name,
            ])
            ->all();
    }

    /**
     * Cantidad de salidas y minutos fuera de cada período, en una sola consulta.
     * Una salida sin retorno cuenta hasta este momento; una anterior a hoy sin
     * retorno no cuenta (se avisa aparte).
     *
     * @param  array<string, Carbon>  $periods  Nombre => inicio del período.
     * @return array<string, array{exits: int, minutes: int}>
     */
    private function totals(User $user, array $periods, Carbon $since, ?Carbon $until, Carbon $now): array
    {
        $seconds = $this->secondsOutSql();

        $columns = [];
        $bindings = [];
        foreach ($periods as $name => $start) {
            $columns[] = "SUM(CASE WHEN leave_time >= ? THEN 1 ELSE 0 END) AS {$name}_exits";
            $bindings[] = $start->toDateTimeString();
            $columns[] = "SUM(CASE WHEN leave_time >= ? THEN {$seconds} ELSE 0 END) AS {$name}_seconds";
            $bindings[] = $start->toDateTimeString();
            $bindings[] = $now->toDateTimeString();
        }

        $row = DB::table('records')
            ->selectRaw(implode(', ', $columns), $bindings)
            ->where('user_id', $user->user_id)
            ->where('leave_time', '>=', $since->toDateTimeString())
            ->when($until, fn ($query) => $query->where('leave_time', '<=', $until->toDateTimeString()))
            ->whereRaw('NOT (return_time IS NULL AND leave_time < ?)', [$now->copy()->startOfDay()->toDateTimeString()])
            ->first();

        $totals = [];
        foreach (array_keys($periods) as $name) {
            $totals[$name] = [
                'exits' => (int) ($row->{"{$name}_exits"} ?? 0),
                'minutes' => (int) round(((float) ($row->{"{$name}_seconds"} ?? 0)) / 60),
            ];
        }

        return $totals;
    }

    /**
     * Segundos que estuvo fuera en una fila (hasta el retorno, o hasta el
     * momento recibido como parámetro si aún no volvió); nunca negativo.
     * Lleva un `?` con ese momento.
     */
    private function secondsOutSql(): string
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? 'GREATEST(0, EXTRACT(EPOCH FROM (COALESCE(return_time, CAST(? AS timestamp)) - leave_time)))'
            : "MAX(0, CAST(strftime('%s', COALESCE(return_time, ?)) AS INTEGER) - CAST(strftime('%s', leave_time) AS INTEGER))";
    }
}
