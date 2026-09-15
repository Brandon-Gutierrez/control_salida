<?php

namespace App\Models;

use App\Models\ReasonPremise;
use App\Models\Record;
use App\Models\Role;
use App\Models\UserActiveSession;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'user_id';
    // Atributos que se pueden asignar masivamente
    protected $fillable = [ 
        'external_identifier',
        'name',
        'item',
        'role_id'
        ];
    // Atributos que deben ser convertidos a tipos nativos
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
    protected $hidden = [
        'remember_token',
    ];
    //Relación muchos a muchos con el modelo ReasonPremise
    public function records()
    {
        return $this->hasMany(Record::class);
    }
    public function reasonPremise()
    {
        return $this->belongsToMany(ReasonPremise::class, 'records')
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }
    public function role()
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
            'role_id'
        );
    }

    public function activeSession()
    {
    return $this->hasOne(
        UserActiveSession::class,
        'user_id',
        'user_id'
    );
    }
}
