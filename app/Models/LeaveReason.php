<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Motivo de salida del catálogo (sincronizado desde el sistema externo). */
class LeaveReason extends Model
{
    protected $table = 'reasons';

    protected $primaryKey = 'reason_id';

    protected $fillable = [
        'name',
        'code',
    ];

    public function premises(): BelongsToMany
    {
        return $this->belongsToMany(Premise::class, 'reason_premise', 'reason_id', 'premise_id')
            ->using(ReasonPremise::class)
            ->withTimestamps();
    }
}
