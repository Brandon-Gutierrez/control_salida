<?php

namespace App\Repositories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    /**
     * Crea o actualiza la cuenta de una persona que se autenticó en el sistema
     * externo. Las cuentas nuevas son EMPLEADOS.
     */
    public function upsertFromExternal(string $externalIdentifier, string $name, string $item): User
    {
        $user = User::firstWhere('external_identifier', $externalIdentifier);

        if ($user) {
            $user->update(['name' => $name, 'item' => $item]);

            return $user;
        }

        // Si la base se creó sin sembrar los roles, el primer usuario nuevo
        // fallaba por la llave foránea: se garantiza que EMPLOYEE exista.
        $role = Role::firstOrCreate(['name' => Role::EMPLOYEE]);

        return User::create([
            'external_identifier' => $externalIdentifier,
            'name' => $name,
            'item' => $item,
            'role_id' => $role->role_id,
        ]);
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function find(int $userId): ?User
    {
        return User::find($userId);
    }

    public function findByUsername(string $username): ?User
    {
        return User::where('username', $username)->first();
    }

    /** Busca por usuario local, o —si es numérico— por user_id o item. */
    public function findByLoginKey(string $key): ?User
    {
        return User::where('username', $key)
            ->orWhere(fn ($query) => ctype_digit($key)
                ? $query->where('user_id', (int) $key)->orWhere('item', (int) $key)
                : $query->whereRaw('1 = 0'))
            ->first();
    }

    public function itemExists(int $item): bool
    {
        return User::where('item', $item)->exists();
    }

    /** Todas las cuentas con su rol, predio y dispositivos, ordenadas por nombre. */
    public function allForAdmin(): Collection
    {
        return User::query()
            ->select('user_id', 'name', 'item', 'role_id', 'premise_id', 'username')
            ->with([
                'role:role_id,name',
                'premise:premise_id,name',
                'devices:id,user_id,platform,bound_at',
            ])
            ->orderBy('name')
            ->get();
    }

    /** Responsables del predio, sin contar a `$exceptUserId`. */
    public function premiseManagersOf(int $premiseId, ?int $exceptUserId = null): Collection
    {
        return User::where('premise_id', $premiseId)
            ->when($exceptUserId, fn ($query) => $query->where('user_id', '!=', $exceptUserId))
            ->premiseManagers()
            ->get();
    }
}
