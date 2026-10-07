<?php

namespace App\Models;

use App\Models\ReasonPremise;
use App\Models\Record;
use App\Models\Role;
use App\Models\UserActiveSession;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// Representa un usuario.
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
        'role_id',
        'username',
        'password',
        'premise_id',
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
        'password',
    ];
    //Relación muchos a muchos con el modelo ReasonPremise
    public function records()
    {
        return $this->hasMany(Record::class);
    }
    // Obtiene los motivos relacionados.
    public function reasonPremise()
    {
        return $this->belongsToMany(ReasonPremise::class, 'records')
        ->using(Record::class)
        ->withPivot('leave_time', 'return_time');
    }
    // Obtiene el rol del usuario.
    public function role()
    {
        return $this->belongsTo(
            Role::class,
            'role_id',
            'role_id'
        );
    }

    // Obtiene la sesión activa.
    public function activeSession()
    {
    return $this->hasOne(
        UserActiveSession::class,
        'user_id',
        'user_id'
    );
    }

    /** Dispositivos autorizados: uno por aplicación (web / mobile). */
    public function devices()
    {
        return $this->hasMany(UserDevice::class, 'user_id', 'user_id');
    }

    // Obtiene el predio del usuario.
    public function premise()
    {
        return $this->belongsTo(Premise::class, 'premise_id', 'premise_id');
    }

    /** true si es el responsable de un predio (rol MANAGE_PREMISE). */
    public function isPremiseManager(): bool
    {
        return strtoupper($this->role?->name ?? '') === Role::MANAGE_PREMISE;
    }

    // Obtiene la política de salida.
    public function leavePolicy()
    {
        return $this->hasOne(UserLeavePolicy::class, 'user_id', 'user_id');
    }
}
