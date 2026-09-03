<?php 
namespace App\Repositories;

use App\Models\LeavePremise;
use App\Models\Leave;

class LeavePremiseRepository
{

    //Obtener las salidas de un predio
    public function getLeaves(int $premiseId)
    {
        $leavesId = LeavePremise::where('premise_id', $premiseId)
            ->pluck('leave_id');
        
        $reasons = Leave::whereIn('id', $leavesId)
            ->pluck('reason');

        return $reasons;
    }

    public function getLeaveId(String $reason) : int
    {
        $leaveId = Leave::where('reason', $reason)
            ->value('id');
        return $leaveId;
    }
    public function findALeavePremise(int $premiseId, String $reason) : int
    {

        $leaveId = $this->getLeaveId($reason);
        $leavePremise = LeavePremise::where([
            'premise_id' => $premiseId,
            'leave_id' => $leaveId,
            ])->value('id');
        return $leavePremise;
    }
}