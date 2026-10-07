<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

// Representa un registro de salida.
class Record extends Pivot
{
    protected $table = 'records';
    protected $primaryKey = 'record_id';
    public $incrementing = true;

    public $timestamps = false;

    protected $fillable = [
        'leave_time',
        'return_time',
        'user_id',
        'reason_premise_id',
    ];

    // Obtiene el usuario relacionado.
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    // Obtiene los motivos relacionados.
    public function reasonPremise()
    {
        return $this->belongsTo(ReasonPremise::class, 'reason_premise_id');
    }
}
