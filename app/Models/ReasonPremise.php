<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/**
 * Motivo de salida habilitado en un predio.
 *
 * Las salidas registradas apuntan a esta fila, así que nunca se borra de
 * verdad: al retirar el motivo del predio se marca `deleted_at` y, si se
 * vuelve a habilitar, se restaura la misma fila (el historial sigue enlazado).
 */
class ReasonPremise extends Model
{
    use SoftDeletes;

    protected $table = 'reason_premise';

    protected $fillable = [
        'reason_id',
        'premise_id',
    ];

    protected static function booted(): void
    {
        static::forceDeleting(function () {
            throw new LogicException('Las relaciones motivo-predio no se pueden eliminar: tienen historial asociado.');
        });
    }

    /** El historial sigue mostrando el motivo aunque Comteco ya no lo ofrezca. */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(LeaveReason::class, 'reason_id', 'reason_id')->withTrashed();
    }

    public function premise(): BelongsTo
    {
        return $this->belongsTo(Premise::class, 'premise_id', 'premise_id')->withTrashed();
    }
}
