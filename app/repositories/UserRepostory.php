<?php
namespace App\Repositories;

use App\Models\User;
use App\Models\Premise;
use App\Models\Record;
use App\Models\LeavePremise;

class LeaveRepository
{
    //Obtener el id del usuario
    public function getUserId(int $item)
    {
        return User::where('item', $item)
            ->first()
            ->get('id');
    }

    //Obtener de que garita es responsable el usuario
    public function getPremiseResponsible(int $userId)
    {
        return Premise::where('user_id', $userId)
            ->first()
            ->get('id', 'name');
    }

    //Obtner si el usuario esta con salida marcada
    public function isUserLeave(int $userId)
    {
        return Record::where('user_id', $userId)
            ->first();
    }

    //Registrar usuario
    public function registerUser(String $username, String $name, String $item){
        User::create([
            'username', $username,
            'name', $name,
            'item', $item,
            'created_at', now(),
        ]);
    }

    //Registrar la salida temporal del usuario
    public function registerLeave(int $userId, int $leave_premise_id)
    {
        Record::create([
            'leave_time', now(),
            'return_time', null,
            'user_id', $userId,
            'leave_premise_id', $leave_premise_id,
        ]);
    }

    //Registrar el retorno del usuario
    public function registerReturn(int $userId)
    {
        Record::where('user_id', $userId)->update([
            'return_time', now(),
        ]);
    }

}