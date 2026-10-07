<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\AssignUserPremiseRequest;
use App\Http\Requests\ResetUserDeviceRequest;
use App\Http\Requests\UpdateUserPasswordRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Account\UserRoleService;
use App\Services\Auth\DeviceBindingService;
use App\Services\Premise\PremiseManagerService;
use App\Support\ClientPlatform;
use Illuminate\Http\JsonResponse;

/** Administración de cuentas. */
class UserController extends Controller
{
    /** Todas las cuentas registradas, con su rol, predio y dispositivos. */
    public function index(UserRepository $users): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => $users->allForAdmin(),
        ]);
    }

    /** Asigna un rol a la cuenta (otorgar o quitar permisos de ADMIN, o hacerla responsable de un predio). */
    public function updateRole(UpdateUserRoleRequest $request, User $user, UserRoleService $roles): JsonResponse
    {
        $updated = $roles->change(
            $request->user(),
            $user,
            $request->validated('role_id'),
            $request->validated('premise_id'),
        );

        return response()->json(['status' => 0, 'data' => $updated]);
    }

    /** Cambia el predio del que es responsable una cuenta MANAGE_PREMISE. */
    public function assignPremise(AssignUserPremiseRequest $request, User $user, PremiseManagerService $managers): JsonResponse
    {
        $updated = $managers->moveToPremise($user, (int) $request->validated('premise_id'));

        return response()->json(['status' => 0, 'data' => $updated]);
    }

    /** Cambia o regenera la contraseña de una cuenta local de responsable. */
    public function updatePassword(UpdateUserPasswordRequest $request, User $user, PremiseManagerService $managers): JsonResponse
    {
        $generatedPassword = $managers->changePassword($user, $request->validated('password'));

        return response()->json([
            'status' => 0,
            'message' => 'Contraseña actualizada.',
            'generated_password' => $generatedPassword,
        ]);
    }

    /**
     * Desvincula el dispositivo de la cuenta en una aplicación (web o mobile)
     * y cierra sus sesiones ahí, para que pueda entrar desde uno nuevo.
     */
    public function resetDevice(ResetUserDeviceRequest $request, User $user, DeviceBindingService $devices): JsonResponse
    {
        $platform = $request->validated('platform');

        if (! ClientPlatform::allows($platform, $user->role?->name)) {
            throw ApiException::failure(422, 'Esta cuenta no usa esa aplicación, no tiene dispositivo que desvincular.');
        }

        // Quitar el propio navegador cerraría la sesión desde la que se pide.
        if ($request->user()->user_id === $user->user_id
            && $request->session()->get(ClientPlatform::SESSION_KEY) === $platform) {
            throw ApiException::failure(
                422,
                'No puede desvincular el dispositivo que está usando. Pídalo a otro administrador o a TI.',
            );
        }

        $hadDevice = $devices->reset($user, $platform);

        return response()->json([
            'status' => 0,
            'message' => $hadDevice
                ? 'Dispositivo anterior desactivado. La cuenta puede iniciar sesión desde un dispositivo nuevo.'
                : 'La cuenta no tenía un dispositivo vinculado en esa aplicación.',
            'user_id' => $user->user_id,
            'platform' => $platform,
        ]);
    }
}
