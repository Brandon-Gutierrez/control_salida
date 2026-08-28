<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeavePremise extends Model
{
    use Notifiable, HasFactory;
    protected $table = 'leave_premise';

    // Atributos que se pueden asignar masivamente
    protected $fillable = [
        'leave_id',
        'premise_id',
    ];

    // Atributos que deben ser convertidos a tipos nativos
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];
    public function users(){
        return $this->belongsToMany(User::class)
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }

}
