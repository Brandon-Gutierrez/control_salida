<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Predio desde el que el personal registra sus salidas. */
class Premise extends Model
{
    protected $table = 'premises';

    protected $primaryKey = 'premise_id';

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
    ];

    /** Motivos de salida permitidos en el predio. */
    public function reasons(): BelongsToMany
    {
        return $this->belongsToMany(LeaveReason::class, 'reason_premise', 'premise_id', 'reason_id')
            ->using(ReasonPremise::class)
            ->withTimestamps();
    }

    /** Responsables del predio (cuentas con rol MANAGE_PREMISE). */
    public function managers(): HasMany
    {
        return $this->hasMany(User::class, 'premise_id', 'premise_id')->premiseManagers();
    }
}
