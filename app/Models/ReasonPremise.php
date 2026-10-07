<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Motivo de salida habilitado en un predio. */
class ReasonPremise extends Pivot
{
    protected $table = 'reason_premise';

    protected $fillable = [
        'reason_id',
        'premise_id',
    ];

    public function reason(): BelongsTo
    {
        return $this->belongsTo(LeaveReason::class, 'reason_id', 'reason_id');
    }

    public function premise(): BelongsTo
    {
        return $this->belongsTo(Premise::class, 'premise_id', 'premise_id');
    }
}
