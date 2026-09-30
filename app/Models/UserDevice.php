<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dispositivo autorizado de una cuenta en una aplicación (web o mobile). */
class UserDevice extends Model
{
    protected $table = 'user_devices';

    protected $fillable = ['user_id', 'platform', 'device_hash', 'bound_at'];

    protected $hidden = ['device_hash'];

    protected function casts(): array
    {
        return ['bound_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
