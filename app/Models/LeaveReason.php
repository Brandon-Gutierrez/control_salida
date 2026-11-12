<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Motivo de salida del catálogo (sincronizado desde el sistema externo).
 *
 * Un motivo que Comteco ya no envía se desactiva con borrado lógico: deja de
 * ofrecerse en nuevas salidas, pero el historial lo sigue mostrando. Si
 * Comteco lo vuelve a enviar se restaura.
 */
class LeaveReason extends Model
{
    use SoftDeletes;

    protected $table = 'reasons';

    protected $primaryKey = 'reason_id';

    protected $fillable = [
        'name',
        'code',
    ];

    /** Predios donde el motivo está habilitado (sin las relaciones retiradas). */
    public function premises(): BelongsToMany
    {
        return $this->belongsToMany(Premise::class, 'reason_premise', 'reason_id', 'premise_id')
            ->withTimestamps()
            ->wherePivotNull('deleted_at');
    }
}
