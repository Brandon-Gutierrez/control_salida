<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Record extends Model
{
    protected $fillable = [
        'leave_time',
        'return_time',
        'user_id',
        'leave_premise_id',
    ];

    public function user(){
        return $this->belongsToMany(User::class)
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }
}
