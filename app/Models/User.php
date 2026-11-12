<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Persona que usa el sistema. Las cuentas de empleados y administradores se
 * crean al iniciar sesión con el sistema externo; las de responsable de
 * predio son locales (con `username` y `password` propios).
 */
class User extends Authenticatable
{
    use SoftDeletes;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'external_identifier',
        'name',
        'item',
        'role_id',
        'username',
        'password',
        'premise_id',
    ];

    /**
     * `external_identifier` (token de Comteco) solo lo necesita el servidor
     * para reconocer la cuenta: no sale en ninguna respuesta de la API.
     */
    protected $hidden = [
        'remember_token',
        'password',
        'external_identifier',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function premise(): BelongsTo
    {
        return $this->belongsTo(Premise::class, 'premise_id', 'premise_id');
    }

    /** Dispositivos autorizados: uno por aplicación (web / mobile). */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class, 'user_id', 'user_id');
    }

    /** Solo las cuentas con rol MANAGE_PREMISE. */
    public function scopePremiseManagers(Builder $query): Builder
    {
        return $query->whereHas('role', fn (Builder $role) => $role->whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE]));
    }

    public function isPremiseManager(): bool
    {
        return strtoupper($this->role?->name ?? '') === Role::MANAGE_PREMISE;
    }
}
