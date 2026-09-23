<?php 
namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use App\Models\Record;


class RecordRepository
{
    public function isSamePremise(int $userId, int $premiseId): bool
    {
        $record = Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->with('reasonPremise')
            ->latest('leave_time')
            ->first();

        return $record?->reasonPremise?->premise_id === $premiseId;
    }
}