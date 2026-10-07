<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\Record;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

/** Límite de salidas general: se aplica igual a todas las personas. */
class LeaveLimitService
{
    public const PERIODS = ['day', 'week', 'month'];

    private const PERIOD_KEY = 'leave_limit_period';

    private const MAX_EXITS_KEY = 'leave_limit_max_exits';

    private const MAX_PER_PREMISE_KEY = 'leave_limit_max_per_premise';

    /** @return array{period: string, max_exits: ?int, max_exits_per_premise: ?int} */
    public function policy(): array
    {
        $period = Setting::get(self::PERIOD_KEY, 'day');
        $toInt = fn (?string $value) => ($value === null || $value === '') ? null : (int) $value;

        return [
            'period' => in_array($period, self::PERIODS, true) ? $period : 'day',
            'max_exits' => $toInt(Setting::get(self::MAX_EXITS_KEY)),
            'max_exits_per_premise' => $toInt(Setting::get(self::MAX_PER_PREMISE_KEY)),
        ];
    }

    /** Guarda el límite; null en un tope significa "sin tope". */
    public function savePolicy(string $period, ?int $maxExits, ?int $maxExitsPerPremise): void
    {
        Setting::set(self::PERIOD_KEY, $period);
        Setting::set(self::MAX_EXITS_KEY, $maxExits === null ? '' : (string) $maxExits);
        Setting::set(self::MAX_PER_PREMISE_KEY, $maxExitsPerPremise === null ? '' : (string) $maxExitsPerPremise);
    }

    /**
     * Devuelve el límite alcanzado si la próxima salida no está permitida, o
     * null si puede salir.
     *
     * @return array{limit_type: string, allowed: int, used: int, period: string}|null
     */
    public function check(User $user, int $premiseId): ?array
    {
        $policy = $this->policy();
        if (! $policy['max_exits'] && ! $policy['max_exits_per_premise']) {
            return null;
        }

        [$start, $end] = $this->periodBounds($policy['period']);
        $records = Record::query()
            ->where('user_id', $user->user_id)
            ->whereBetween('leave_time', [$start, $end]);

        $totalCount = (clone $records)->count();
        if ($policy['max_exits'] !== null && $totalCount >= $policy['max_exits']) {
            return [
                'limit_type' => 'total',
                'allowed' => $policy['max_exits'],
                'used' => $totalCount,
                'period' => $policy['period'],
            ];
        }

        if ($policy['max_exits_per_premise'] !== null) {
            $premiseCount = (clone $records)
                ->whereHas('reasonPremise', fn ($query) => $query->where('premise_id', $premiseId))
                ->count();

            if ($premiseCount >= $policy['max_exits_per_premise']) {
                return [
                    'limit_type' => 'premise',
                    'allowed' => $policy['max_exits_per_premise'],
                    'used' => $premiseCount,
                    'period' => $policy['period'],
                ];
            }
        }

        return null;
    }

    /** @throws ApiException si la persona ya alcanzó el límite de salidas. */
    public function assertWithinLimit(User $user, int $premiseId): void
    {
        $limit = $this->check($user, $premiseId);

        if ($limit) {
            throw ApiException::withBody(403, $this->rejectionPayload($limit));
        }
    }

    public function rejectionPayload(array $limit): array
    {
        $limitLabel = $limit['limit_type'] === 'total'
            ? 'de salidas totales'
            : 'de salidas a este predio';

        return [
            'status' => 1,
            'code' => 'LEAVE_LIMIT_REACHED',
            'limit_type' => $limit['limit_type'],
            'period' => $limit['period'],
            'message' => "Alcanzó el límite {$limitLabel} para el período configurado.",
            'limit' => $limit['allowed'],
            'used' => $limit['used'],
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} Inicio y fin del período en curso. */
    private function periodBounds(string $period): array
    {
        $now = now();

        return match ($period) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            'week' => [
                $now->copy()->startOfWeek(Carbon::MONDAY),
                $now->copy()->endOfWeek(Carbon::SUNDAY),
            ],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
        };
    }
}
