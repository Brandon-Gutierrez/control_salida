<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Record;

class UserRepository
{
    //Registrar usuario
    public function registerUser(String $external_identifier, String $name, String $item) : User
    {
        return User::updateOrCreate(
            [
            'external_identifier'=> $external_identifier,
            ], 
            [
            'name'=> $name,
            'item'=> $item,
            ]
        );
    }
    //Obtener el id del usuario mediante el item
    public function getUserId(String $external_identifier) : ?int
    {
        return User::where('external_identifier', $external_identifier)->value('id');
    }
    public function getUserData(int $userId) : User
    {
        return User::where('id', $userId)->first();
    }

    //Verifica si el usuario es admin
    public function isUserAdmin(String $item) : bool
    {
        $userId = $this->getUserId($item);

        if (!$userId) return false;
        $isAdmin = User::where([
            'id'=> $userId,
            'role_id' => 2,
        ])->first();
        if (!$isAdmin) return false;
        return true;
    }

    //Obtner si el usuario esta con salida marcada
    public function isUserLeave(?int $userId) : ?Record
    {
        return Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->latest('leave_time')
            ->first();
    }

    //Registrar la salida temporal del usuario
    public function registerLeave(int $userId, int $reason_premise_id) : Record
    {
        return Record::create([
            'leave_time'=> now(),
            'return_time'=> null,
            'user_id'=> $userId,
            'reason_premise_id'=> $reason_premise_id,
        ]);
    }

    //Registrar el retorno del usuario
    public function registerReturn(int $userId) : int
    {
        return Record::where('user_id', $userId)
            ->whereNull('return_time')
            ->update([
            'return_time'=> now(),
        ]);
    }
}