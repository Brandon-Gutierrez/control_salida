<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Premise extends Model
{
    protected $fillable = [
        'name',
        'user_id',
    ];

    protected $casts =[
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',  
    ];

    public function leaves()
    {
        return $this->belongsToMany(Leave::class, 'leave_premise')
        ->using(LeavePremise::class)
        ->withTimestamps();
    }
    public function user(){
        return $this->belongsTo(User::class);
    }
}
