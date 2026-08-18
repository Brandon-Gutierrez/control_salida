<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Atributos que se pueden asignar masivamente
    protected $fillable = [ 
        'name',
        'email',
        'password',
        'item',
        'id_card',]
    ];

    // Atributos que deben permanecer ocultos para las matrices
    protected $hidden = [
        'password',
    ];

    // Atributos que deben ser convertidos a tipos nativos
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
    //Relación muchos a muchos con el modelo Leave
    public function leaves()
    {
        return $this->belongsToMany(Leave::class, 'leave_user', 'user_id', 'leave_id')
                    ->withPivot('leave_time', 'return_time'); 
    }
}
