<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\LeavePremise;

class Premise extends Model
{
    protected $fillable = [
        'name',
    ];

    protected $casts =[
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',  
    ];

    public function leave()
    {
        return $this->belongsToMany(Leave::class)
        ->using(LeavePremise::class)
        ->withTimestamps(true);
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
}
