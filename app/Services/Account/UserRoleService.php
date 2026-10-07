<?php

namespace App\Services\Account;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Models\User;
use App\Repositories\RoleRepository;
use App\Repositories\UserActiveSessionRepository;
use App\Services\Premise\PremiseManagerService;

/** Cambio de rol de una cuenta (p. ej. otorgar o quitar permisos de ADMIN). */
class UserRoleService
{
    public function __construct(
        private RoleRepository $roles,
        private PremiseManagerService $premiseManagers,
        private UserActiveSessionRepository $sessions,
    ) {}

    /**
     * Asigna un rol a la cuenta. Para MANAGE_PREMISE hace falta un predio (el
     * indicado o el que ya tenga); al salir de ese rol el predio se desasigna.
     * Sus sesiones se cierran para que vuelva a entrar con el rol nuevo.
     *
     * @throws ApiException si el cambio no está permitido.
     */
    public function change(User $actor, User $user, int $roleId, ?int $premiseId): User
    {
        // Un administrador que se quitara su propio rol (o se volviera
        // MANAGE_PREMISE, que no puede cerrar sesión) quedaría sin acceso.
        if ($actor->user_id === $user->user_id) {
            throw ApiException::failure(422, 'No puede cambiar su propio rol. Pídalo a otro administrador.');
        }

        $newRole = $this->roles->findOrFail($roleId);
        $becomesManager = strtoupper($newRole->name) === Role::MANAGE_PREMISE;

        // Las cuentas locales (con usuario/contraseña propios) solo sirven como
        // responsable de predio: no existen en el sistema externo.
        if ($user->username !== null && ! $becomesManager) {
            throw ApiException::failure(
                422,
                'Esta cuenta se creó solo para gestionar un predio y no puede tener otro rol.',
            );
        }

        $managedPremiseId = null;
        if ($becomesManager) {
            $managedPremiseId = $premiseId ?? $user->premise_id;
            if (! $managedPremiseId) {
                throw ApiException::failure(422, 'Elija el predio del que será responsable.');
            }

            $this->premiseManagers->assertPremiseIsFree((int) $managedPremiseId, $user->user_id);
        }

        $user->update(['role_id' => $newRole->role_id, 'premise_id' => $managedPremiseId]);

        // Los permisos cambian de raíz: nunca queda una pantalla vieja abierta.
        $this->sessions->deleteAllForUser($user->user_id);

        return $user->fresh()->load(['role', 'premise']);
    }
}
