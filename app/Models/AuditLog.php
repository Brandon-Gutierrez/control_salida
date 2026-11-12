<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Entrada de la bitácora de auditoría. Solo se agrega: nunca se edita ni se borra. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $fillable = [
        'event',
        'actor_user_id',
        'actor_name',
        'subject_type',
        'subject_id',
        'subject_name',
        'details',
        'ip_address',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('La bitácora no se puede modificar.'));
        static::deleting(fn () => throw new LogicException('La bitácora no se puede borrar.'));
    }
}
