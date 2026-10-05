<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
use App\Services\DeviceBindingService;
use App\Services\EmployeeProfileService;
use App\Services\LeaveStatsService;
use App\Support\ClientPlatform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use App\Repositories\UserRepository;


class UserController extends Controller
{
    //Lista todos los usuarios registrados con su rol 
    public function index(): JsonResponse
    {
        $users = User::query()
            ->select('user_id', 'name', 'item', 'role_id', 'premise_id', 'username')
            ->with([
                'role:role_id,name',
                'premise:premise_id,name',
                'devices:id,user_id,platform,bound_at',
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            "status" => 0,
            "data" => $users,
        ], 200);
    }

    //Lista el catálogo de roles disponibles (EMPLOYEE, ADMIN, ...)
    public function roles(): JsonResponse
    {
        return response()->json([
            "status" => 0,
            "data" => Role::orderBy('name')->get(['role_id', 'name']),
        ], 200);
    }

    //Asigna un rol a un usuario (p. ej. otorgar o quitar permisos de ADMIN).
    //Para MANAGE_PREMISE hace falta un predio (enviado en la misma petición o
    //ya asignado); al salir de ese rol el predio se desasigna.
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,role_id'],
            'premise_id' => ['nullable', 'integer', 'exists:premises,premise_id'],
        ]);

        // Un administrador que se quitara su propio rol (o se volviera
        // MANAGE_PREMISE, que no puede cerrar sesión) quedaría sin acceso.
        if ($request->user()->user_id === $user->user_id) {
            return response()->json([
                'status' => 1,
                'message' => 'No puede cambiar su propio rol. Pídalo a otro administrador.',
            ], 422);
        }

        $newRole = Role::findOrFail($data['role_id']);
        $becomesManager = strtoupper($newRole->name) === Role::MANAGE_PREMISE;

        // Las cuentas locales (con usuario/contraseña propios) solo sirven como
        // responsable de predio: no existen en el sistema externo.
        if ($user->username !== null && !$becomesManager) {
            return response()->json([
                'status' => 1,
                'message' => 'Esta cuenta se creó solo para gestionar un predio y no puede tener otro rol.',
            ], 422);
        }

        $premiseId = null;
        if ($becomesManager) {
            $premiseId = $data['premise_id'] ?? $user->premise_id;
            if (!$premiseId) {
                return response()->json([
                    'status' => 1,
                    'message' => 'Elija el predio del que será responsable.',
                ], 422);
            }
        }

        if ($premiseId && ($taken = $this->premiseTakenBy((int) $premiseId, $user->user_id))) {
            return $this->premiseTakenResponse($taken);
        }

        $user->update(['role_id' => $newRole->role_id, 'premise_id' => $premiseId]);

        // Los permisos cambian de raíz: se cierran sus sesiones para que
        // vuelva a entrar con el rol nuevo (nunca queda una pantalla vieja abierta).
        UserActiveSession::where('user_id', $user->user_id)->delete();

        return response()->json([
            "status" => 0,
            "data" => $user->fresh()->load(['role', 'premise']),
        ], 200);
    }

    //Cambia el predio del que es responsable una cuenta MANAGE_PREMISE
    public function assignPremise(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'premise_id' => ['required', 'integer', 'exists:premises,premise_id'],
        ]);

        if (!$user->isPremiseManager()) {
            return response()->json([
                'status' => 1,
                'message' => 'Solo las cuentas de gestor de predio tienen un predio asignado.',
            ], 422);
        }

        if ($taken = $this->premiseTakenBy((int) $data['premise_id'], $user->user_id)) {
            return $this->premiseTakenResponse($taken);
        }

        // La sesión abierta no se cierra: el QR siempre se genera con el predio
        // que el servidor tiene asignado en ese momento.
        $user->update(['premise_id' => $data['premise_id']]);

        return response()->json([
            'status' => 0,
            'data' => $user->fresh()->load(['role', 'premise']),
        ], 200);
    }


    /**
     * Desvincula el dispositivo de una cuenta en una aplicación (web o mobile)
     * y cierra sus sesiones ahí, para que pueda entrar desde uno nuevo.
     */
    public function resetUserDevice(Request $request, User $user, DeviceBindingService $devices): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'string', 'in:' . implode(',', ClientPlatform::ALL)],
        ]);
        $platform = $data['platform'];

        if (!ClientPlatform::allows($platform, $user->role?->name)) {
            return response()->json([
                'status' => 1,
                'message' => 'Esta cuenta no usa esa aplicación, no tiene dispositivo que desvincular.',
            ], 422);
        }

        // Quitar el propio navegador cerraría la sesión desde la que se pide.
        if ($request->user()->user_id === $user->user_id
            && $request->session()->get(ClientPlatform::SESSION_KEY) === $platform) {
            return response()->json([
                'status' => 1,
                'message' => 'No puede desvincular el dispositivo que está usando. Pídalo a otro administrador o a TI.',
            ], 422);
        }

        $hadDevice = $devices->reset($user, $platform);

        return response()->json([
            'status' => 0,
            'message' => $hadDevice
                ? 'Dispositivo anterior desactivado. La cuenta puede iniciar sesión desde un dispositivo nuevo.'
                : 'La cuenta no tenía un dispositivo vinculado en esa aplicación.',
            'user_id' => $user->user_id,
            'platform' => $platform,
        ], 200);
    }

    /** Crea una cuenta local responsable de un único predio. */
    public function createPremiseManager(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:100', 'alpha_dash', 'unique:users,username'],
            'password' => ['sometimes', 'nullable', 'string', 'min:10'],
            'premise_id' => ['required', 'integer', 'exists:premises,premise_id'],
        ]);

        if ($taken = $this->premiseTakenBy((int) $data['premise_id'], null)) {
            return $this->premiseTakenResponse($taken);
        }

        $password = $data['password'] ?? Str::random(20);
        $roleId = Role::whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE])->firstOrFail()->role_id;

        $item = random_int(1_000_000_000, 2_000_000_000);
        while (User::where('item', $item)->exists()) {
            $item = random_int(1_000_000_000, 2_000_000_000);
        }

        $user = User::create([
            'external_identifier' => 'premise-manager:' . $data['username'],
            'name' => $data['name'],
            'item' => $item,
            'role_id' => $roleId,
            'premise_id' => $data['premise_id'],
            'username' => $data['username'],
            'password' => Hash::make($password),
        ]);

        return response()->json([
            'status' => 0,
            'data' => $user->load(['role', 'premise']),
            'generated_password' => array_key_exists('password', $data) && $data['password'] !== null
                ? null
                : $password,
        ], 201);
    }

    /** Cambia o regenera la contraseña de una cuenta local de responsable. */
    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'password' => ['sometimes', 'nullable', 'string', 'min:10', 'max:100'],
        ]);

        if (!$user->isPremiseManager() || $user->username === null) {
            return response()->json([
                'status' => 1,
                'message' => 'Solo las cuentas locales de responsable de predio tienen contraseña propia.',
            ], 422);
        }

        $provided = $data['password'] ?? null;
        $password = $provided ?: Str::random(20);
        $user->update(['password' => Hash::make($password)]);

        // La contraseña anterior deja de servir: se cierran las sesiones abiertas.
        UserActiveSession::where('user_id', $user->user_id)->delete();

        return response()->json([
            'status' => 0,
            'message' => 'Contraseña actualizada.',
            'generated_password' => $provided ? null : $password,
        ], 200);
    }

    /** Otro responsable (MANAGE_PREMISE) que ya tiene asignado el predio. */
    private function premiseTakenBy(int $premiseId, ?int $exceptUserId): ?User
    {
        return User::where('premise_id', $premiseId)
            ->when($exceptUserId, fn ($q) => $q->where('user_id', '!=', $exceptUserId))
            ->whereHas('role', fn ($q) => $q->whereRaw('UPPER(name) = ?', [Role::MANAGE_PREMISE]))
            ->first();
    }

    private function premiseTakenResponse(User $holder): JsonResponse
    {
        return response()->json([
            'status' => 1,
            'message' => "Ese predio ya tiene como responsable a {$holder->name}. "
                . 'Solo puede haber uno: cámbielo desde Editar predio.',
        ], 422);
    }
    /** Rol, foto y cargo (servicio de terceros) y estadísticas de salidas (base local). */
    private function profileExtras(User $user): array
    {
        $profile = app(EmployeeProfileService::class)->forUser($user);

        return [
            'role' => $user->role?->name,
            'photo_url' => $profile['photo_url'],
            'job_title' => $profile['job_title'],
            'area' => $profile['area'],
            'stats' => app(LeaveStatsService::class)->forUser($user),
        ];
    }

    /** Estadísticas de salidas del usuario autenticado: día, semana y mes en curso. */
    public function leaveStats(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => app(LeaveStatsService::class)->forUser($request->user()),
        ], 200);
    }

    //Obtener los datos y el estado de salida del usuario autenticado
    public function leaveStatus(Request $request) : JsonResponse
    {
        $authUser = $request->user();
        $item = $authUser->item;

        //LOGICA CON API
        $date = now()->format('Y-m-d');
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
        ])->post(env('API_GETCHECKOUT'), [
            'in_item' => $item,
            'in_fecha' => $date,
        ]);
        if($response->failed())
        {
            return response()->json([
                "status" => 1,
                 "message" => "Error externo"
                 ], 400);
        }

        $userData = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->get(env('API_GETEMPLOYEE'), [
                'item' => $item
            ]); 
        //Si la solicitud devolvio error o no se proceso
        if($userData->failed() || $userData->json("status") == 1)
        {
            return response()->json([
                "status" => 1,
                 "message" => "Error externo"
                 ], 400);
        }

        if ($response->json('error') === -1 && empty($response->json('data')) )
        {
            return response()->json([
                "name" => $userData->json("name") ?? $userData['name'],
                "item" => $userData->json("item") ??$userData["item"],
                "token" => $userData->json("token"),
                "isLeave" => false,
            ...$this->profileExtras($authUser),
            ], 200);
        }
        //Sino
        $data = $response->json("data");
        $dateLeave = $data[0]['fecha_salida']
            ? Carbon::createFromFormat('Y-m-d H:i:s', $data[0]['fecha_salida'])->toIso8601String()
            : null;

        // El motivo de la salida no viene del sistema externo, así que se
        // busca en el registro local guardado al confirmar la salida.
        // Se desempata por record_id porque leave_time se guarda con
        // precisión de segundos y dos salidas podrían registrarse en el mismo segundo.
        $reasonName = Record::where('user_id', $authUser->user_id)
            ->orderByDesc('leave_time')
            ->orderByDesc('record_id')
            ->with('reasonPremise.leave')
            ->first()
            ?->reasonPremise
            ?->leave
            ?->name;

        return response()->json([
            "name" => $userData['name'],
            "item" => $userData["item"],
            "token" => $userData->json("token"),
            "isLeave" => true,
            "dateLeave" => $dateLeave,
            "reason" => $reasonName,
            ...$this->profileExtras($authUser),
        ], 200);
    }
}
