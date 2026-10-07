<?php

namespace App\Services\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\External\ExternalAuthService;
use App\Support\ClientPlatform;
use Illuminate\Support\Facades\Hash;

/**
 * Reglas para iniciar sesión: identifica la aplicación y el dispositivo,
 * valida las credenciales (locales para responsables de predio, externas para
 * el resto) y comprueba el acceso por rol, predio y dispositivo.
 */
class AuthService
{
    public function __construct(
        private ExternalAuthService $externalAuth,
        private UserRepository $users,
        private DeviceBindingService $devices,
    ) {}

    /**
     * @param  string  $platform  Aplicación que inicia sesión (encabezado `X-Client-Platform`).
     * @param  ?string  $deviceId  Identificador del dispositivo (encabezado `DeviceId`).
     *
     * @throws ApiException si las credenciales o el acceso no son válidos.
     */
    public function authenticate(string $username, string $password, string $platform, ?string $deviceId): User
    {
        // Cada aplicación se identifica (web / mobile) y envía el identificador
        // de su dispositivo: el acceso y la vinculación dependen de ambos.
        if (! ClientPlatform::isValid($platform)) {
            throw ApiException::authFailure(
                422,
                'La aplicación no se identificó correctamente. Actualícela e intente de nuevo.',
                'CLIENT_PLATFORM_REQUIRED',
            );
        }

        if (! DeviceBindingService::isValidDeviceId($deviceId)) {
            throw ApiException::authFailure(
                422,
                'No se pudo identificar este dispositivo. Actualice la aplicación e intente de nuevo.',
                'DEVICE_ID_REQUIRED',
            );
        }

        $user = $this->resolveUser($username, $password);

        // web: ADMIN y MANAGE_PREMISE. mobile: ADMIN y EMPLOYEE.
        $roleName = $user->role?->name;
        if (! ClientPlatform::allows($platform, $roleName)) {
            throw ApiException::authFailure(
                403,
                ClientPlatform::roleNotAllowedMessage($platform, $roleName),
                'PLATFORM_NOT_ALLOWED',
            );
        }

        // Un responsable sin predio no tiene nada que mostrar y, como no puede
        // cerrar sesión, no se le abre sesión hasta que administración lo asigne.
        if ($user->isPremiseManager() && ! $user->premise_id) {
            throw ApiException::authFailure(
                403,
                'La cuenta no tiene un predio asignado. Contacte a administración.',
                'PREMISE_NOT_ASSIGNED',
            );
        }

        // Un único dispositivo por cuenta y aplicación: el primero que inicia
        // sesión queda vinculado; para cambiarlo, administración / TI debe
        // desvincular el anterior.
        if (! $this->devices->bindOrVerify($user, $platform, $deviceId)) {
            throw ApiException::authFailure(
                403,
                ClientPlatform::deviceNotAuthorizedMessage($platform),
                'DEVICE_NOT_AUTHORIZED',
            );
        }

        return $user;
    }

    private function resolveUser(string $username, string $password): User
    {
        // Las cuentas responsables de predio usan credenciales locales.
        $localUser = $this->users->findByUsername($username);
        if ($localUser) {
            if (! $localUser->isPremiseManager() || ! Hash::check($password, $localUser->password ?? '')) {
                throw $this->invalidCredentials();
            }

            return $localUser;
        }

        $externalUser = $this->externalAuth->authenticate($username, $password);
        if (! $externalUser) {
            throw $this->invalidCredentials();
        }

        return $this->users->upsertFromExternal(
            $externalUser['external_identifier'],
            $externalUser['name'],
            $externalUser['item'],
        );
    }

    private function invalidCredentials(): ApiException
    {
        return ApiException::authFailure(401, 'Credenciales inválidas.');
    }
}
