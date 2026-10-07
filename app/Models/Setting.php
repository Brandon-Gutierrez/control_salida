<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuración global editable desde el panel de administración (clave-valor). */
class Setting extends Model
{
    protected $table = 'settings';
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    // Obtiene un valor de configuración.
    public static function get(string $key, ?string $default = null): ?string
    {
        return self::query()->find($key)?->value ?? $default;
    }

    // Guarda un valor de configuración.
    public static function set(string $key, string $value): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
