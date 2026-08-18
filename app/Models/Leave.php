<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Leave extends Model
{
    use Notifiable, HasFactory;
    //
    protected $fillable = [
        'reason',
    ];
    // Relación muchos a muchos con el modelo User
    public function users()
    {
        return $this->belongsToMany(User::class, 'leave_user', 'leave_id', 'user_id')
                    ->withPivot('leave_time', 'return_time')
    }
}
