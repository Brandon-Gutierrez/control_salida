<?php

namespace App\Models;

use App\Support\DeviceCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dispositivo autorizado de una cuenta en una aplicación (web o mobile). */
class UserDevice extends Model
{
    protected $table = 'user_devices';

    protected $fillable = ['user_id', 'platform', 'device_hash', 'bound_at'];

    protected $hidden = ['device_hash'];

    /** El código corto (p. ej. «K7M4-Q9XB») sale en el JSON; el hash completo nunca. */
    protected $appends = ['code'];

    /** Código que la persona ve en su app y dicta a Recursos Humanos; null si no se cargó el hash. */
    public function getCodeAttribute(): ?string
    {
        $hash = $this->attributes['device_hash'] ?? null;

        return $hash === null ? null : DeviceCode::fromHash($hash);
    }

    // Define las conversiones de atributos.
    protected function casts(): array
    {
        return ['bound_at' => 'datetime'];
    }

    // Obtiene el usuario relacionado.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
