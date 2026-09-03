<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Premise;
use App\Models\Record;

class UserRepository
{
    //Obtener el id del usuario mediante el item
    public function getUserId(String $item) : ?int
    {
        return User::where('item', $item)->value('id');
    }

    //Verifica si el usuario es admin
    public function isUserAdmin(String $item) : bool
    {
        $userId = $this->getUserId($item);
        if (!$userId) return false;
        $isAdmin = User::where('id', $userId)->value(2);
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
    //Obtener si el usuario ya esta registrado
    public function isUserRegistered(?String $name, ?String $item) : ?User
    {
        if (!$name && !$item) return null;
        return User::where([
            'name'=> $name,
            'item' => $item])
            ->first();
    } 

    //Registrar usuario
    public function registerUser(String $username, String $name, String $item) : User
    {
        return User::firstOrCreate([
            'username'=> $username,
            'name'=> $name,
            'item'=> $item,
            'created_at'=> now(),
        ]);
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