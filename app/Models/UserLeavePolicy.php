<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Representa la política de salida.
class UserLeavePolicy extends Model
{
    protected $table = 'user_leave_policies';

    protected $fillable = [
        'user_id',
        'period',
        'max_exits',
        'max_exits_per_premise',
    ];

    // Obtiene el usuario relacionado.
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
