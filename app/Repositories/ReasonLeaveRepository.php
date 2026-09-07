<?php 
namespace App\Repositories;

use App\Models\ReasonPremise;
use App\Models\ReasonLeave;

class ReasonLeaveRepository
{
    public function syncReasons(array $reasonsData): array
    {
        $SomeNew = [];
        foreach ($reasonsData as $reason){
            $object = ReasonLeave::firstOrNew(['code' => $reason['codigo']]);
            $object->name = $reason['descripcion'];

            $isNew = !$object->exists;
            $isDirty = $object->isDirty();
            $object->save();

            if ($isNew|| $isDirty)
            {
                $SomeNew[] = $object->toArray();
            }
        }
        return $SomeNew;
    }
}