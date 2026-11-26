<?php

namespace App\Services\Leave;

use App\Exceptions\ApiException;
use App\Models\LeaveReason;
use App\Models\User;
use App\Services\External\ExternalApiService;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use ValueError;

class LeaveDetailService
{
    private const MAX_LEAVES_LISTED = 200;

    public function __construct(private ExternalApiService $api) {}

    /** @return list<array{leave_time: string, return_time: ?string, premise: ?string, reason: ?string, minutes: ?int}> */
    public function leavesInRange(User $user, Carbon $from, Carbon $to): array
    {
        try {
            $response = $this->api->fast()->leaveHistory($user->item);
        } catch (ConnectionException) {
            throw ApiException::temporaryFailure('LEAVE_HISTORY_UNAVAILABLE');
        }

        $error = $response->json('error');
        $rows = $response->json('data');
        if (! $response->successful() || ! is_numeric($error) || (float) $error !== 0.0
            || ! is_array($rows) || ! array_is_list($rows)) {
            throw ApiException::temporaryFailure('LEAVE_HISTORY_UNAVAILABLE');
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();
        $leaves = [];

        foreach ($rows as $row) {
            if (! is_array($row) || $this->text($row['COD_EMPLEADO'] ?? null) !== (string) $user->item) {
                continue;
            }

            $left = $this->parseDate($row['FECHA_SALIDA'] ?? null);
            if ($left === null || $left->lt($start) || $left->gt($end)) {
                continue;
            }

            $returnTime = $row['FECHA_RETORNO'] ?? null;
            $returned = $this->parseDate($returnTime);
            if ($returnTime !== null && $returnTime !== '' && $returned === null) {
                continue;
            }

            $leaves[] = [
                'leave_time' => $left->toIso8601String(),
                'return_time' => $returned?->toIso8601String(),
                'premise' => $this->text($row['PREDIO'] ?? null),
                'reason' => $this->text($row['COD_MOTIVO'] ?? null),
                'minutes' => $returned ? max(0, (int) round($left->diffInSeconds($returned, false) / 60)) : null,
            ];
        }

        usort($leaves, fn (array $a, array $b) => $b['leave_time'] <=> $a['leave_time']);
        $leaves = array_slice($leaves, 0, self::MAX_LEAVES_LISTED);

        $codes = array_unique(array_filter(array_column($leaves, 'reason'), fn ($code) => $code !== null));
        if ($codes !== []) {
            $names = LeaveReason::withTrashed()->whereIn('code', $codes)->pluck('name', 'code');
            foreach ($leaves as $index => $leave) {
                $leaves[$index]['reason'] = $names[$leave['reason']] ?? $leave['reason'];
            }
        }

        return $leaves;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $format = 'Y-m-d H:i:s';
        try {
            $date = Carbon::createFromFormat($format, $value, config('app.timezone'));
        } catch (InvalidFormatException|ValueError) {
            return null;
        }

        return $date?->format($format) === $value ? $date : null;
    }

    private function text(mixed $value): ?string
    {
        $text = is_string($value) || is_int($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
