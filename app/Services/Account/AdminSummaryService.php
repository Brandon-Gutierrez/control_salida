<?php

namespace App\Services\Account;

use App\Models\Premise;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contadores de la pantalla de inicio del panel de administración. Se calculan
 * en la base de datos para que "Inicio" no descargue las listas completas de
 * usuarios y predios solo para contarlas.
 */
class AdminSummaryService
{
    /**
     * @return array{
     *     premises: array{total: int, without_location: int, without_manager: int, without_reasons: int},
     *     users: array{total: int, admins: int, managers_without_premise: int, employees_without_app: int}
     * }
     */
    public function summary(): array
    {
        return [
            'premises' => [
                'total' => Premise::count(),
                'without_location' => Premise::where(
                    fn (Builder $query) => $query->whereNull('latitude')->orWhereNull('longitude'),
                )->count(),
                'without_manager' => Premise::whereDoesntHave('managers')->count(),
                'without_reasons' => Premise::whereDoesntHave('reasons')->count(),
            ],
            'users' => [
                'total' => User::count(),
                'admins' => $this->withRole(Role::ADMIN)->count(),
                'managers_without_premise' => $this->withRole(Role::MANAGE_PREMISE)->whereNull('premise_id')->count(),
                // Empleados que nunca iniciaron sesión en la app móvil (sin teléfono vinculado).
                'employees_without_app' => $this->withRole(Role::EMPLOYEE)
                    ->whereDoesntHave('devices', fn (Builder $device) => $device->where('platform', 'mobile'))
                    ->count(),
            ],
        ];
    }

    /** Cuentas con ese rol (el nombre del rol no distingue mayúsculas, como en el resto del sistema). */
    private function withRole(string $role): Builder
    {
        return User::query()->whereHas(
            'role',
            fn (Builder $query) => $query->whereRaw('UPPER(name) = ?', [strtoupper($role)]),
        );
    }
}
