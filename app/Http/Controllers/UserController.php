<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActiveSession;
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
            ->select('user_id', 'name', 'item', 'role_id', 'premise_id', 'device_bound_at')
            ->with(['role:role_id,name', 'premise:premise_id,name', 'leavePolicy'])
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

        // La sesión abierta no se cierra: el QR siempre se genera con el predio
        // que el servidor tiene asignado en ese momento.
        $user->update(['premise_id' => $data['premise_id']]);

        return response()->json([
            'status' => 0,
            'data' => $user->fresh()->load(['role', 'premise']),
        ], 200);
    }

    /** Obtiene los límites de salida configurados para una cuenta. */
    public function leavePolicy(User $user): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => $user->leavePolicy,
        ], 200);
    }

    /** Define o actualiza los límites periódicos de salida de una cuenta. */
    public function updateLeavePolicy(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'in:day,week,month'],
            'max_exits' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_exits_per_premise' => ['present', 'nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $policy = $user->leavePolicy()->updateOrCreate(
            ['user_id' => $user->user_id],
            $data,
        );

        return response()->json([
            'status' => 0,
            'data' => $policy,
        ], 200);
    }

    /** Revoca las sesiones y permite que la cuenta se vincule a un dispositivo nuevo. */
    public function resetUserDevice(Request $request, User $user): JsonResponse
    {
        if (strtoupper($user->role?->name ?? '') !== 'EMPLOYEE') {
            return response()->json([
                'status' => 1,
                'message' => 'La vinculación de dispositivo solo aplica a cuentas de la aplicación móvil.',
            ], 422);
        }

        if ($request->user()->user_id === $user->user_id) {
            return response()->json([
                'status' => 1,
                'message' => 'Otro administrador debe realizar el cambio de dispositivo de esta cuenta.',
            ], 422);
        }

        $user->update([
            'device_id' => null,
            'device_bound_at' => null,
        ]);
        $user->activeSession()->delete();

        return response()->json([
            'status' => 0,
            'message' => 'Dispositivo anterior desactivado. El usuario puede iniciar sesión desde su nuevo dispositivo.',
            'user_id' => $user->user_id,
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
        ], 200);
    }
}
