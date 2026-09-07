<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ReasonLeave extends Model
{
    use HasFactory;
    //
    protected $table = "reason_leaves";
    protected $fillable = [
        'name',
        'code',
    ];
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    // Relación muchos a muchos con el modelo premise
    public function premises()
    {
        return $this->belongsToMany(Premise::class, 'reason_premise')
        ->using(ReasonPremise::class)
        ->withTimestamps();
    }
}
