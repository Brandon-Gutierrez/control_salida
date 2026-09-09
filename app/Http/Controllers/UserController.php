<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use App\Repositories\UserRepository;
use Illuminate\Http\JsonResponse;


class UserController extends Controller
{
    protected UserRepository $userRepository;
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    //Inicio de sesion de un admin en la aplicacion web
    public function loginAdmin (Request $request)
    {
        $username = $request->input("username");
        $password = $request->input("password");

        if(!$username || !$password){
            return response()->json([
                "status" => 1,
                "message" => "Las credenciales no pueden ser nulas"
            ], 400);
        }
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->post(env('API_POSTCOM'), [ 
            'username' => $username,
            'password' => $password
        ]);
        //Verifica si hay un error en los datos 
        if ($response->failed() || $response->json("status") == 1)
        {
            //retorna esetado de error
            return response()->json([
                "status" => 1,
                "message" => "Error en los datos ingresados"
            ], 400);
        }
        $item = $response->json("item");

        $isAdmin = $this->userRepository->isUserAdmin($item);

        if ($isAdmin == false){ 
            return response()->json([
                "status" => 1,
                "message" => "Usuario no autorizado",
            ], 400);
        }
        //retorna los datos necesarios
        return response()->json([
            "status" => 0,
            "name" => $response->json("name"),
            "item" => $response->json("item"),
            "token" => $response->json("token"),
            "message" => "Usuario autorizado",
            ], 200);
    }

    //Inicio de sesión de un empleado en la aplicación móvil
    public function login(Request $request) : JsonResponse
    {

        $username = $request->input("username");
        $password = $request->input("password");
        //Si alguno de los credenciales esta vacio
        if (!$username || !$password)
        {
            return response()->json([
                "status" => 1,
                "message" => "Las credenciales no pueden estar vacias"
            ], 400);
        }
        //Hace la solicitd POSt a la api externa de comteco de login
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->post(env('API_POSTCOM'), [ 
            'username' => $username,
            'password' => $password
        ]);
        //Verifica si hay un error en los datos 
        if ($response->failed() || $response->json("status") == 1)
        {
            //retorna esetado de error
            return response()->json([
                "status" => 0,
                "message" => "Error en la solicitud o en las credendiales"
            ], 400);
        }
        $name = $response->json("name");
        $item = $response->json("item");
        $token = $response->json("token");
        
        //Verifica si el usuario ya existe 
        $data = $this->userRepository->isUserRegistered($name, $item);
        //Si no hay datos los guarda
        if (!$data && $username && $name && $item){
            $this->userRepository->registerUser($username, $name, $item);
        }
        //retorna los datos necesarios
        return response()->json([
            "status" => 1,
            "name" => $name,
            "item" => $item,
            "token" => $token,
            ], 200);
    }

    //Obtener los datos del usuario
    public function getUserData(Request $request) : JsonResponse
    {
        $item = $request->query("item");
        //Si el item esta vacio
        if(!$item){
            return response()->json([
                "status" => 1,
                "message" => "No se puede realizar la conexión"
            ], 400);
        }
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
    }
}