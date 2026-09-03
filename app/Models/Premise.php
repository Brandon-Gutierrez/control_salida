<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Premise extends Model
{
    protected $table = "premises";
    protected $fillable = [
        'name',
    ];

    protected $casts =[
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',  
    ];

    public function leaves()
    {
        return $this->belongsToMany(ReasonLeave::class, 'reason_premise')
        ->using(ReasonPremise::class)
        ->withTimestamps();
    }
}
