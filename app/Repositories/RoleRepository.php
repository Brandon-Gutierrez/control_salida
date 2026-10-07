<?php

namespace App\Repositories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository
{
    /** Todos los roles, ordenados por nombre. */
    public function allOrderedByName(): Collection
    {
        return Role::orderBy('name')->get(['role_id', 'name']);
    }

    public function findOrFail(int $roleId): Role
    {
        return Role::findOrFail($roleId);
    }

    public function premiseManagerRoleId(): int
    {
        return Role::whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE])->firstOrFail()->role_id;
    }
}
