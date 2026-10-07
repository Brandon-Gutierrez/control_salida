<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Salida de una persona y, cuando vuelve, su retorno (`return_time` nulo = sigue fuera). */
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reasonPremise(): BelongsTo
    {
        return $this->belongsTo(ReasonPremise::class, 'reason_premise_id');
    }
}
