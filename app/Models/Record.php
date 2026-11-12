<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use LogicException;

/**
 * Salida de una persona y, cuando vuelve, su retorno (`return_time` nulo = sigue fuera).
 *
 * El historial es permanente y su `record_id` no tiene saltos: nunca se borra.
 */
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

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Las salidas registradas no se pueden eliminar.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id')->withTrashed();
    }

    public function reasonPremise(): BelongsTo
    {
        return $this->belongsTo(ReasonPremise::class, 'reason_premise_id')->withTrashed();
    }
}
