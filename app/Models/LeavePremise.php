<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LeavePremise extends Pivot
{
    use HasFactory;
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

    public function leave()
    {
        return $this->belongsTo(Leave::class);
    }

    public function premise()
    {
        return $this->belongsTo(Premise::class);
    }
    public function users(){
        return $this->belongsToMany(User::class, 'records')
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }

}
