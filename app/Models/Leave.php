<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\LeavePremise;

class Leave extends Model
{
    use Notifiable, HasFactory;
    //
    protected $fillable = [
        'reason',
    ];
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relación muchos a muchos con el modelo premise
    public function premise()
    {
        return $this->belongsToMany(Premise::class)
        ->using(LeavePremise::class)
        ->withTimestamps()
        ->withSoftDeleted(true);
    }
}
