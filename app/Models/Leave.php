<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Leave extends Model
{
    use HasFactory;
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
    public function premises()
    {
        return $this->belongsToMany(Premise::class, 'leave_premise')
        ->using(LeavePremise::class)
        ->withTimestamps();
    }
}
