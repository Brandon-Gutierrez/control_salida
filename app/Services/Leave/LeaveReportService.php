<?php

namespace App\Services\Leave;

use App\Models\Record;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Reporte de salidas para el administrador: cuánto tiempo estuvieron fuera,
 * a dónde (predio) y por qué (motivo), más las advertencias de no retorno.
 *
 * Una salida anterior a hoy sin retorno no suma tiempo ni salidas (igual que
 * en los contadores de cada persona): se lista aparte como advertencia.
 */
class LeaveReportService
{
    /**
     * @param  array{premise_id?: ?int, reason?: ?string, search?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function build(Carbon $from, Carbon $to, array $filters = []): array
    {
        $now = now();
        $startOfToday = $now->copy()->startOfDay();

        $records = $this->records($from->copy()->startOfDay(), $to->copy()->endOfDay(), $filters);

        $isUnreturnedPast = fn (Record $r) => $r->return_time === null
            && Carbon::parse($r->leave_time)->lt($startOfToday);

        $counted = $records->reject($isUnreturnedPast);
        $minutesOf = function (Record $r) use ($now): int {
            $end = $r->return_time ? Carbon::parse($r->return_time) : $now;

            return (int) round(max(0, Carbon::parse($r->leave_time)->diffInSeconds($end, false)) / 60);
        };

        $outsideNow = $records->filter(fn (Record $r) => $r->return_time === null
            && ! $isUnreturnedPast($r));
        $unreturned = $records->filter($isUnreturnedPast);

        $totalMinutes = $counted->sum($minutesOf);
        $exits = $counted->count();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'exits' => $exits,
                'minutes' => $totalMinutes,
                'average_minutes' => $exits > 0 ? (int) round($totalMinutes / $exits) : 0,
                'people' => $counted->pluck('user_id')->unique()->count(),
                'outside_now' => $outsideNow->count(),
                'unreturned' => $unreturned->count(),
            ],
            'by_premise' => $this->group($counted, $minutesOf,
                fn (Record $r) => $r->reasonPremise?->premise_id,
                fn (Record $r) => $r->reasonPremise?->premise?->name ?? 'Sin predio',
                'premise_id'),
            'by_reason' => $this->group($counted, $minutesOf,
                fn (Record $r) => $r->reasonPremise?->reason_id,
                fn (Record $r) => $r->reasonPremise?->reason?->name ?? 'Sin motivo',
                'reason_id'),
            'by_user' => $this->byUser($counted, $unreturned, $minutesOf),
            'unreturned' => $this->warnings($unreturned, $minutesOf),
            'outside_now' => $this->warnings($outsideNow, $minutesOf),
        ];
    }

    /** @return Collection<int, Record> */
    private function records(Carbon $start, Carbon $end, array $filters): Collection
    {
        $query = Record::query()
            ->with(['user:user_id,name,item', 'reasonPremise.reason', 'reasonPremise.premise'])
            ->where('leave_time', '>=', $start)
            ->where('leave_time', '<=', $end);

        if (! empty($filters['premise_id'])) {
            $query->whereHas('reasonPremise', fn ($q) => $q->where('premise_id', $filters['premise_id']));
        }
        if (! empty($filters['reason'])) {
            $query->whereHas('reasonPremise.reason', fn ($q) => $q->where('name', $filters['reason']));
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->whereHas('user', fn ($q) => $q
                ->whereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('CAST(item AS TEXT) LIKE ?', [$like]));
        }

        return $query->orderByDesc('leave_time')->get();
    }

    /**
     * @return array<int, array<string, mixed>> Ordenado por tiempo total, mayor primero.
     */
    private function group(Collection $records, callable $minutesOf, callable $keyOf, callable $nameOf, string $idKey): array
    {
        return $records
            ->groupBy(fn (Record $r) => $keyOf($r) ?? 0)
            ->map(function (Collection $items, $key) use ($minutesOf, $nameOf, $idKey) {
                $minutes = $items->sum($minutesOf);

                return [
                    $idKey => (int) $key,
                    'name' => $nameOf($items->first()),
                    'exits' => $items->count(),
                    'minutes' => $minutes,
                    'average_minutes' => (int) round($minutes / max(1, $items->count())),
                    'people' => $items->pluck('user_id')->unique()->count(),
                ];
            })
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byUser(Collection $counted, Collection $unreturned, callable $minutesOf): array
    {
        $unreturnedByUser = $unreturned->countBy('user_id');

        return $counted
            ->groupBy('user_id')
            ->map(function (Collection $items, $userId) use ($minutesOf, $unreturnedByUser) {
                $minutes = $items->sum($minutesOf);
                $user = $items->first()->user;

                return [
                    'user_id' => (int) $userId,
                    'name' => $user?->name ?? 'Desconocido',
                    'item' => $user?->item,
                    'exits' => $items->count(),
                    'minutes' => $minutes,
                    'average_minutes' => (int) round($minutes / max(1, $items->count())),
                    'longest_minutes' => (int) $items->map($minutesOf)->max(),
                    'unreturned' => (int) ($unreturnedByUser[$userId] ?? 0),
                ];
            })
            // Quien solo tiene salidas sin retorno también aparece.
            ->union($unreturned->groupBy('user_id')
                ->reject(fn ($items, $userId) => $counted->contains('user_id', $userId))
                ->map(fn (Collection $items, $userId) => [
                    'user_id' => (int) $userId,
                    'name' => $items->first()->user?->name ?? 'Desconocido',
                    'item' => $items->first()->user?->item,
                    'exits' => 0,
                    'minutes' => 0,
                    'average_minutes' => 0,
                    'longest_minutes' => 0,
                    'unreturned' => $items->count(),
                ]))
            ->sortByDesc('minutes')
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function warnings(Collection $records, callable $minutesOf): array
    {
        return $records->map(fn (Record $r) => [
            'record_id' => $r->record_id,
            'user_id' => $r->user_id,
            'name' => $r->user?->name ?? 'Desconocido',
            'item' => $r->user?->item,
            'premise' => $r->reasonPremise?->premise?->name,
            'reason' => $r->reasonPremise?->reason?->name,
            'left_at' => Carbon::parse($r->leave_time)->toIso8601String(),
            'minutes' => $minutesOf($r),
        ])->values()->all();
    }
}
