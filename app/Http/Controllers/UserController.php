<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Record;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

use App\Repositories\UserRepository;


class UserController extends Controller
{
    //Lista todos los usuarios registrados con su rol 
    public function index(): JsonResponse
    {
        $users = User::query()
            ->select('user_id', 'name', 'item', 'role_id')
            ->with('role:role_id,name')
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

    //Asigna un rol a un usuario (p. ej. otorgar o quitar permisos de ADMIN)
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,role_id'],
        ]);

        $user->update(['role_id' => $data['role_id']]);

        return response()->json([
            "status" => 0,
            "data" => $user->fresh()->load('role'),
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