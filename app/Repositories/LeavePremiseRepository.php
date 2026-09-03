<?php 
namespace App\Repositories;

use App\Models\ReasonPremise;
use App\Models\ReasonLeave;

class ReasonPremiseRepository
{
    //Obtener las salidas de un predio
    public function getLeavesofPremise(int $premiseId)
    {
        $leavesId = ReasonPremise::where('premise_id', $premiseId)
            ->pluck('leave_id');
        
        $reasons = ReasonLeave::whereIn('id', $leavesId)
            ->pluck('name');

        return $reasons;
    }

    //Obtener el id de una razón de salida
    public function getLeaveId(String $name) : int
    {
        $reasonId = ReasonLeave::where('name', $name)
            ->value('id');
        return $reasonId;
    }

    //Encontrar el id de una salida de un predio
    public function findALeavePremise(int $premiseId, String $name) : int
    {

        $reasonId = $this->getLeaveId($name);
        $reasonPremise = ReasonPremise::where([
            'premise_id' => $premiseId,
            'reason_id' => $reasonId,
            ])->value('id');
        return $reasonPremise;
    }
}