<?php 
namespace App\Repositories;

use App\Models\ReasonPremise;
use App\Models\ReasonLeave;

class ReasonPremiseRepository
{
    //Obtener las salidas de un predio
    public function getReasonsOfPremise(?int $premiseId) 
    {
        $leavesId = ReasonPremise::where('premise_id', $premiseId)
            ->pluck('reason_id');
        
        $reasons = ReasonLeave::whereIn('reason_id', $leavesId)
            ->orderBy('name')
            ->pluck('name');

        return $reasons;
    }

    //Obtener el id de una razón de salida
    public function getReasonId(String $nameReason) : ?int
    {
        $reasonId = ReasonLeave::where('name', $nameReason)
            ->value('reason_id');
        return $reasonId;
    }

    //Encontrar el id de una salida de un predio
    public function findAReasonPremise(int $premiseId, int $reasonId) : ?int
    {
        $reasonPremise = ReasonPremise::where([
            'premise_id' => $premiseId,
            'reason_id' => $reasonId,
            ])->value('id');
        return $reasonPremise;
    }
}