<?php

namespace App\Services;

use App\Models\Record;
use App\Models\User;
use Carbon\Carbon;

class LeaveQuotaService
{
    /** Returns a limit error payload when the next exit is not allowed. */
    public function check(User $user, int $premiseId): ?array
    {
        $policy = $user->leavePolicy;
        if (!$policy || (!$policy->max_exits && !$policy->max_exits_per_premise)) {
            return null;
        }

        [$start, $end] = $this->periodBounds($policy->period);
        $records = Record::query()
            ->where('user_id', $user->user_id)
            ->whereBetween('leave_time', [$start, $end]);

        $totalCount = (clone $records)->count();
        if ($policy->max_exits !== null && $totalCount >= $policy->max_exits) {
            return [
                'limit_type' => 'total',
                'allowed' => $policy->max_exits,
                'used' => $totalCount,
                'period' => $policy->period,
            ];
        }

        if ($policy->max_exits_per_premise !== null) {
            $premiseCount = (clone $records)
                ->whereHas('reasonPremise', fn ($query) => $query->where('premise_id', $premiseId))
                ->count();

            if ($premiseCount >= $policy->max_exits_per_premise) {
                return [
                    'limit_type' => 'premise',
                    'allowed' => $policy->max_exits_per_premise,
                    'used' => $premiseCount,
                    'period' => $policy->period,
                ];
            }
        }

        return null;
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
