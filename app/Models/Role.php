<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    public const EMPLOYEE = 'EMPLOYEE';

    public const ADMIN = 'ADMIN';

    /** Responsable de un único predio: solo ve la pantalla de QR de su predio. */
    public const MANAGE_PREMISE = 'MANAGE_PREMISE';

    protected $table = 'roles';

    protected $primaryKey = 'role_id';

    protected $fillable = [
        'name',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_id', 'role_id');
    }
}
