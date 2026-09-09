<?php 
namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use App\Models\Record;


class RecordRepository
{
    public function isSamePremise(String $premiseName) : bool
    {
        $premiseNameStorage = Record::whereNull('return_time')
            ->with('reasonPremise.premise')
            ->latest('leave_time') //toma la salida sin retorno más reciente
            ->first()
            ?->reasonPremise
            ?->premise
            ?->name;

        if (mb_strtolower(trim($premiseNameStorage)) ===  mb_strtolower(trim($premiseName)))
        {
            return true;
        } 
        return false;
    }
}