<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Representa un predio.
class Premise extends Model
{
    protected $table = "premises";
    protected $primaryKey = 'premise_id';
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
    ];

    protected $casts =[
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',  
    ];

    // Obtiene las salidas relacionadas.
    public function leaves()
    {
        return $this->belongsToMany(ReasonLeave::class, 'reason_premise', 'premise_id', 'reason_id')
        ->using(ReasonPremise::class)
        ->withTimestamps();
    }

    // Obtiene los responsables del predio.
    public function responsibleUsers()
    {
        return $this->hasMany(User::class, 'premise_id', 'premise_id');
    }
}
