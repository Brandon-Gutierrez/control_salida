<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Record extends Pivot
{
    protected $table = "records";

    protected $fillable = [
        'leave_time',
        'return_time',
        'user_id',
        'leave_premise_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function leavePremise()
    {
        return $this->belongsTo(LeavePremise::class, 'leave_premise_id');
    }
}
