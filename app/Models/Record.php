<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Record extends Pivot
{
    protected $table = 'records';

    public $timestamps = false;

    protected $fillable = [
        'leave_time',
        'return_time',
        'user_id',
        'reason_premise_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function reasonPremise()
    {
        return $this->belongsTo(ReasonPremise::class, 'reason_premise_id');
    }
}
