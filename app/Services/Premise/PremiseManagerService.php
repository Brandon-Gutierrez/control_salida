<?php

namespace App\Services\Premise;

use App\Exceptions\ApiException;
use App\Models\Premise;
use App\Models\User;
use App\Repositories\RoleRepository;
use App\Repositories\UserActiveSessionRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Responsables de predio: cuentas locales (rol MANAGE_PREMISE) que solo
 * generan el QR de un único predio, y cada predio tiene como máximo una.
 */
class PremiseManagerService
{
    private const GENERATED_PASSWORD_LENGTH = 20;

    private const EXTERNAL_ITEM_MIN = 1_000_000_000;

    private const EXTERNAL_ITEM_MAX = 2_000_000_000;

    public function __construct(
        private UserRepository $users,
        private RoleRepository $roles,
        private UserActiveSessionRepository $sessions,
    ) {}

    /**
     * Deja a un usuario MANAGE_PREMISE como único responsable del predio (o lo
     * deja sin responsable si es null). El responsable anterior queda sin
     * predio y sus sesiones se cierran, porque una cuenta sin predio no puede
     * operar.
     *
     * @throws ApiException si el usuario no tiene el rol MANAGE_PREMISE.
     */
    public function assign(Premise $premise, ?int $userId): void
    {
        $newManager = null;
        if ($userId !== null) {
            $newManager = $this->users->find($userId);
            if (! $newManager || ! $newManager->isPremiseManager()) {
                throw ApiException::failure(422, 'El responsable debe ser un usuario con el rol MANAGE_PREMISE.');
            }
        }

        DB::transaction(function () use ($premise, $newManager) {
            foreach ($this->users->premiseManagersOf($premise->premise_id, $newManager?->user_id) as $previous) {
                $previous->update(['premise_id' => null]);
                $this->sessions->deleteAllForUser($previous->user_id);
            }

            $newManager?->update(['premise_id' => $premise->premise_id]);
        });
    }

    /**
     * Cambia el predio de un responsable sin cerrar su sesión: el QR siempre
     * se genera con el predio que el servidor tiene asignado en ese momento.
     *
     * @throws ApiException si la cuenta no es de responsable o el predio ya tiene otro.
     */
    public function moveToPremise(User $user, int $premiseId): User
    {
        if (! $user->isPremiseManager()) {
            throw ApiException::failure(422, 'Solo las cuentas de gestor de predio tienen un predio asignado.');
        }

        $this->assertPremiseIsFree($premiseId, $user->user_id);

        $user->update(['premise_id' => $premiseId]);

        return $user->fresh()->load(['role', 'premise']);
    }

    /**
     * Crea una cuenta local de responsable de un único predio.
     *
     * @param  array{name: string, username: string, password?: ?string, premise_id: int}  $data
     * @return array{0: User, 1: ?string} La cuenta y la contraseña generada
     *                                    (null si el administrador indicó una).
     *
     * @throws ApiException si el predio ya tiene responsable.
     */
    public function create(array $data): array
    {
        $this->assertPremiseIsFree((int) $data['premise_id'], null);

        $password = $data['password'] ?? Str::random(self::GENERATED_PASSWORD_LENGTH);

        $user = $this->users->create([
            'external_identifier' => 'premise-manager:'.$data['username'],
            'name' => $data['name'],
            'item' => $this->newUniqueItem(),
            'role_id' => $this->roles->premiseManagerRoleId(),
            'premise_id' => $data['premise_id'],
            'username' => $data['username'],
            'password' => Hash::make($password),
        ]);

        $wasGenerated = ! array_key_exists('password', $data) || $data['password'] === null;

        return [$user->load(['role', 'premise']), $wasGenerated ? $password : null];
    }

    /**
     * Cambia o regenera la contraseña de una cuenta local de responsable y
     * cierra sus sesiones abiertas (la anterior deja de servir).
     *
     * @return ?string La contraseña generada (null si se indicó una).
     *
     * @throws ApiException si la cuenta no es un responsable local.
     */
    public function changePassword(User $user, ?string $provided): ?string
    {
        if (! $user->isPremiseManager() || $user->username === null) {
            throw ApiException::failure(
                422,
                'Solo las cuentas locales de responsable de predio tienen contraseña propia.',
            );
        }

        $password = $provided ?: Str::random(self::GENERATED_PASSWORD_LENGTH);
        $user->update(['password' => Hash::make($password)]);

        $this->sessions->deleteAllForUser($user->user_id);

        return $provided ? null : $password;
    }

    /**
     * El predio no puede tener ya otro responsable.
     *
     * @throws ApiException con el nombre del responsable actual.
     */
    public function assertPremiseIsFree(int $premiseId, ?int $exceptUserId): void
    {
        $holder = $this->users->premiseManagersOf($premiseId, $exceptUserId)->first();

        if ($holder) {
            throw ApiException::failure(
                422,
                "Ese predio ya tiene como responsable a {$holder->name}. "
                .'Solo puede haber uno: cámbielo desde Editar predio.',
            );
        }
    }

    /** `item` aleatorio y sin usar: estas cuentas no existen en el sistema externo. */
    private function newUniqueItem(): int
    {
        do {
            $item = random_int(self::EXTERNAL_ITEM_MIN, self::EXTERNAL_ITEM_MAX);
        } while ($this->users->itemExists($item));

        return $item;
    }
}
