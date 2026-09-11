<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';
    // Atributos que se pueden asignar masivamente
    protected $fillable = [ 
        'username',
        'name',
        'item',
        'device_id',
        'rol_id'
        ];
    // Atributos que deben ser convertidos a tipos nativos
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    //Relación muchos a muchos con el modelo LeavePremise
    public function records()
    {
        return $this->hasMany(Record::class);
    }
    public function leavePremise()
    {
        return $this->belongsToMany(ReasonPremise::class, 'records')
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
}
