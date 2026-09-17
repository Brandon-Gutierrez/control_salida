<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

use App\Repositories\UserRepository;


class UserController extends Controller
{
    //Lista todos los usuarios registrados con su rol (panel de administración)
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
        $item = $request->query('item');

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

        return response()->json([
            "name" => $userData['name'],
            "item" => $userData["item"],
            "token" => $userData->json("token"),
            "isLeave" => true,
            "dateLeave" => $dateLeave,
        ], 200);
        
        // LOGICA CON BASE DE DATOS LOCAL
        /*
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 
        //Si la solicitud devolvio error o no se proceso
        if($response->failed() || $response->json("status") == 1)
        {
            return response()->json([
                "status" => 1,
                 "message" => "Error externo"
                 ], 400);
        }

        //Obtener id del usuario mediante su item
        
        $userId = $this->userRepository->getUserId($item);
        //Obtener si el usurio esta afuera
        $isLeave = $this->userRepository->isUserLeave($userId);

        $leaveTime = $isLeave?->leave_time ?? $isLeave?->pivot?->leave_time;
        
        //Si esta afuera
        if(!$isLeave){
            return response()->json([
                "name" => $response->json("name"),
                "item" => $response->json("item"),
                "token" => $response->json("token"),
                "isLeave" => false,
            ], 200);
        }
        //Sino
        return response()->json([
            "name" => $response->json("name"),
            "item" => $response->json("item"),
            "token" => $response->json("token"),
            "isLeave" => true,
            "dateLeave" => $leaveTime ? Carbon::parse($isLeave->leave_time)->toIso8601String() : null,
        ], 200);
        */
        // FIN LOGICA CON BASE DE DATOS LOCAL
    }
}