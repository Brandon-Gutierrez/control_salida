<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeaveUser extends Model
{
    use Notifiable, HasFactory;


    // Atributos que se pueden asignar masivamente
    protected $fillable = [
        'user_id',
        'leave_id',
        'leave_time',
        'return_time',
    ];

    // Atributos que deben ser convertidos a tipos nativos
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'leave_time' => 'datetime',
        'return_time' => 'datetime',
    ];

    //La hora de salida debe ser antes de la hora de retorno
    public function setLeaveTimeAttribute($value)
    {
        if (isset($this->attributes['return_time']) && $value > $this->attributes['return_time']) { 
            throw new \InvalidArgumentException('La hora de salida debe ser antes de la hora de retorno.');
        }
        $this->attributes['leave_time'] = $value;
    }
}
