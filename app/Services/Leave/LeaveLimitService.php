<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\LeaveReason;
use App\Models\Record;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

/**
 * Límites de salidas, iguales para todas las personas:
 * - por persona: cuántas salidas en total en el período;
 * - por motivo: cuántas veces puede elegir un mismo motivo en su propio
 *   período, con un tope general (el mismo para cada motivo) o uno por motivo.
 */
class LeaveLimitService
{
    public const PERIODS = ['day', 'week', 'month'];

    public const REASON_MODES = ['general', 'custom'];

    private const PERIOD_KEY = 'leave_limit_period';

    private const MAX_EXITS_KEY = 'leave_limit_max_exits';

    private const REASON_PERIOD_KEY = 'reason_limit_period';

    private const REASON_MODE_KEY = 'reason_limit_mode';

    private const REASON_GENERAL_KEY = 'reason_limit_general';

    /** JSON `{reason_id: tope}`; un motivo sin entrada no tiene tope. */
    private const REASON_CUSTOM_KEY = 'reason_limit_custom';

    /**
     * @return array{
     *     period: string,
     *     max_exits: ?int,
     *     reason_limit: array{
     *         period: string,
     *         mode: string,
     *         general: ?int,
     *         custom: array<int, array{reason_id: int, name: string, max: ?int}>
     *     }
     * }
     */
    public function policy(): array
    {
        $caps = $this->customCaps();

        return [
            'period' => $this->periodSetting(self::PERIOD_KEY),
            'max_exits' => $this->intSetting(self::MAX_EXITS_KEY),
            'reason_limit' => [
                'period' => $this->periodSetting(self::REASON_PERIOD_KEY),
                'mode' => $this->reasonMode(),
                'general' => $this->intSetting(self::REASON_GENERAL_KEY),
                // Todos los motivos del catálogo, con su tope (null = sin tope).
                'custom' => LeaveReason::orderBy('name')->get(['reason_id', 'name'])
                    ->map(fn ($reason) => [
                        'reason_id' => $reason->reason_id,
                        'name' => $reason->name,
                        'max' => $caps[$reason->reason_id] ?? null,
                    ])->all(),
            ],
        ];
    }

    /**
     * Guarda los límites; null en un tope significa "sin tope".
     *
     * @param  array<int, ?int>  $customCaps  Tope por id de motivo (modo personalizado).
     */
    public function savePolicy(
        string $period,
        ?int $maxExits,
        string $reasonPeriod = 'day',
        string $reasonMode = 'general',
        ?int $reasonGeneral = null,
        array $customCaps = [],
    ): void {
        Setting::set(self::PERIOD_KEY, $period);
        Setting::set(self::MAX_EXITS_KEY, $maxExits === null ? '' : (string) $maxExits);

        Setting::set(self::REASON_PERIOD_KEY, $reasonPeriod);
        Setting::set(self::REASON_MODE_KEY, $reasonMode);
        Setting::set(self::REASON_GENERAL_KEY, $reasonGeneral === null ? '' : (string) $reasonGeneral);
        Setting::set(self::REASON_CUSTOM_KEY, json_encode(
            array_filter($customCaps, fn ($max) => $max !== null),
            JSON_FORCE_OBJECT,
        ));
    }

    /**
     * Devuelve el límite por persona alcanzado si la próxima salida no está
     * permitida, o null si puede salir.
     *
     * @return array{limit_type: string, allowed: int, used: int, period: string}|null
     */
    public function check(User $user): ?array
    {
        $policy = $this->policy();
        if (! $policy['max_exits']) {
            return null;
        }

        [$start, $end] = $this->periodBounds($policy['period']);
        $used = Record::query()
            ->where('user_id', $user->user_id)
            ->whereBetween('leave_time', [$start, $end])
            ->count();

        if ($used < $policy['max_exits']) {
            return null;
        }

        return [
            'limit_type' => 'total',
            'allowed' => $policy['max_exits'],
            'used' => $used,
            'period' => $policy['period'],
        ];
    }

    /** @throws ApiException si la persona ya alcanzó el límite de salidas. */
    public function assertWithinLimit(User $user): void
    {
        $limit = $this->check($user);

        if ($limit) {
            throw ApiException::withBody(403, $this->rejectionPayload($limit));
        }
    }

    public function rejectionPayload(array $limit): array
    {
        return [
            'status' => 1,
            'code' => 'LEAVE_LIMIT_REACHED',
            'limit_type' => $limit['limit_type'],
            'period' => $limit['period'],
            'message' => 'Alcanzó el límite de salidas totales para el período configurado.',
            'limit' => $limit['allowed'],
            'used' => $limit['used'],
        ];
    }

    /**
     * De los motivos dados, los que esta persona ya usó tantas veces como
     * permite el límite por motivo. Solo se marcan (no impiden el registro).
     *
     * @param  iterable<string>  $reasonNames
     * @return array<int, array{name: string, limit: int, used: int, period: string}>
     */
    public function blockedReasons(User $user, iterable $reasonNames): array
    {
        $reasonLimit = $this->policy()['reason_limit'];
        $caps = $reasonLimit['mode'] === 'general' ? null : $this->customCaps();
        if ($reasonLimit['mode'] === 'general' && $reasonLimit['general'] === null) {
            return [];
        }
        if ($caps !== null && $caps === []) {
            return [];
        }

        [$start, $end] = $this->periodBounds($reasonLimit['period']);
        $used = Record::query()
            ->join('reason_premise', 'reason_premise.id', '=', 'records.reason_premise_id')
            ->where('records.user_id', $user->user_id)
            ->whereBetween('records.leave_time', [$start, $end])
            ->groupBy('reason_premise.reason_id')
            ->selectRaw('reason_premise.reason_id as reason_id, count(*) as used')
            ->pluck('used', 'reason_id');

        $blocked = [];
        foreach (LeaveReason::whereIn('name', collect($reasonNames)->all())->get(['reason_id', 'name']) as $reason) {
            $max = $caps === null ? $reasonLimit['general'] : ($caps[$reason->reason_id] ?? null);
            $times = (int) ($used[$reason->reason_id] ?? 0);

            if ($max !== null && $times >= $max) {
                $blocked[] = [
                    'name' => $reason->name,
                    'limit' => $max,
                    'used' => $times,
                    'period' => $reasonLimit['period'],
                ];
            }
        }

        return $blocked;
    }

    /** @return array<int, int> Tope por id de motivo del modo personalizado. */
    private function customCaps(): array
    {
        $decoded = json_decode(Setting::get(self::REASON_CUSTOM_KEY, '{}') ?? '{}', true);

        return collect(is_array($decoded) ? $decoded : [])
            ->mapWithKeys(fn ($max, $reasonId) => [(int) $reasonId => (int) $max])
            ->filter(fn ($max) => $max > 0)
            ->all();
    }

    private function reasonMode(): string
    {
        $mode = Setting::get(self::REASON_MODE_KEY, 'general');

        return in_array($mode, self::REASON_MODES, true) ? $mode : 'general';
    }

    private function periodSetting(string $key): string
    {
        $period = Setting::get($key, 'day');

        return in_array($period, self::PERIODS, true) ? $period : 'day';
    }

    private function intSetting(string $key): ?int
    {
        $value = Setting::get($key);

        return ($value === null || $value === '') ? null : (int) $value;
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
