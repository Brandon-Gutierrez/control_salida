<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Models\UserDevice;
use App\Services\Auth\DeviceBindingService;
use App\Support\ClientPlatform;
use App\Support\DeviceCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Código de dispositivo: lo ve la persona en su app y lo busca administración en el panel. */
class DeviceController extends Controller
{
    /**
     * Público (sin sesión): código de este dispositivo y si ya está vinculado a
     * una cuenta. Así quien no puede iniciar sesión porque su teléfono quedó
     * vinculado a otra cuenta puede dictar el código a Recursos Humanos.
     */
    public function show(Request $request): JsonResponse
    {
        $deviceId = $request->header('DeviceId');
        if (! DeviceBindingService::isValidDeviceId($deviceId)) {
            throw ApiException::failure(
                422,
                'No se pudo identificar este dispositivo. Actualice la aplicación e intente de nuevo.',
                'DEVICE_ID_REQUIRED',
                false,
            );
        }

        $platform = strtolower((string) $request->header(ClientPlatform::HEADER));
        if (! ClientPlatform::isValid($platform)) {
            $platform = ClientPlatform::MOBILE;
        }

        $hash = hash('sha256', $deviceId);

        return response()->json([
            'status' => 0,
            'code' => DeviceCode::fromHash($hash),
            'linked' => UserDevice::where('platform', $platform)->where('device_hash', $hash)->exists(),
        ]);
    }

    /** Administración: a qué cuentas está vinculado el dispositivo con ese código. */
    public function lookup(Request $request): JsonResponse
    {
        $input = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        $prefix = DeviceCode::hashPrefix($input);
        if ($prefix === null) {
            throw ApiException::failure(
                422,
                'El código no es válido. Debe tener 8 caracteres, por ejemplo K7M4-Q9XB.',
                'DEVICE_CODE_INVALID',
                false,
            );
        }

        $devices = UserDevice::query()
            ->where('device_hash', 'like', $prefix.'%')
            ->with('user:user_id,name,item,role_id', 'user.role:role_id,name')
            ->orderBy('bound_at', 'desc')
            ->get()
            ->filter(fn (UserDevice $device) => $device->user !== null)
            ->map(fn (UserDevice $device) => [
                'user_id' => $device->user->user_id,
                'name' => $device->user->name,
                'item' => $device->user->item,
                'role' => $device->user->role?->name,
                'platform' => $device->platform,
                'bound_at' => $device->bound_at?->toIso8601String(),
                'code' => DeviceCode::fromHash($device->device_hash),
            ])
            ->values();

        return response()->json(['status' => 0, 'data' => $devices]);
    }
}
