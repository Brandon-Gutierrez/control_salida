<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Predio desde el que el personal registra sus salidas. */
class Premise extends Model
{
    use SoftDeletes;

    protected $table = 'premises';

    protected $primaryKey = 'premise_id';

    protected $fillable = [
        'name',
        'latitude',
        'longitude',
    ];

    /** Motivos de salida permitidos en el predio (sin las relaciones retiradas). */
    public function reasons(): BelongsToMany
    {
        return $this->belongsToMany(LeaveReason::class, 'reason_premise', 'premise_id', 'reason_id')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }

    /** Responsables del predio (cuentas con rol MANAGE_PREMISE). */
    public function managers(): HasMany
    {
        return $this->hasMany(User::class, 'premise_id', 'premise_id')->premiseManagers();
    }
}
