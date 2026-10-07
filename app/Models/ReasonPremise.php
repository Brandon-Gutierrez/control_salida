<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Factories\HasFactory;

// Relaciona motivos y predios.
class ReasonPremise extends Pivot
{
    use HasFactory;
    protected $table = 'reason_premise';

    // Atributos que se pueden asignar masivamente
    protected $fillable = [
        'reason_id',
        'premise_id',
    ];

    // Atributos que deben ser convertidos a tipos nativos
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Procesa la operación solicitada.
    public function leave()
    {
        return $this->belongsTo(ReasonLeave::class, 'reason_id', 'reason_id');
    }

    // Obtiene el predio del usuario.
    public function premise()
    {
        return $this->belongsTo(Premise::class, 'premise_id', 'premise_id');
    }
    // Procesa la operación solicitada.
    public function users(){
        return $this->belongsToMany(User::class, 'records')
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }

}
