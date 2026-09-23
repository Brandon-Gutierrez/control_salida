<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function leaves()
    {
        return $this->belongsToMany(ReasonLeave::class, 'reason_premise', 'premise_id', 'reason_id')
        ->using(ReasonPremise::class)
        ->withTimestamps();
    }

    public function responsibleUsers()
    {
        return $this->hasMany(User::class, 'premise_id', 'premise_id');
    }
}
