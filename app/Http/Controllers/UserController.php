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

    public function login(Request $request) : JsonResponse
    {

        $username = $request->input("username");
        $password = $request->input("password");
        
        //Hace la solicitd POSt a la api externa de login
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
            return response()->json($response->json(), 400);
        }
        $name = $response->json("name");
        $item = $response->json("item");
        
        //Verifica si el usuario ya existe 
        $data = $this->userRepository->isUserRegistered($name, $item);
        //Si no hay datos los guarda
        if (!$data && $username && $name && $item){
            $this->userRepository->registerUser($username, $name, $item);
        }
        //retorna los datos necesarios
        return response()->json([
            "token" => $response->json("token"),
            "item" => $response->json("item"),
            "name" => $response->json("name"),
            ], 200);
    }

    public function getUserStatus(Request $request) : JsonResponse
    {
        $item = $request->input("item");
        if(!$item){
            return response()->json([
                "status" => 0,
                "message" => "No se puede realizar la conexión"
            ], 400);
        }
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 
        
        if($response->failed() || $response->json("status") == 1)
        {
            return response()->json($response->json() ?? ["status" => 1, "message" => "Error externo"], 400);
        }

        $userId = $this->userRepository->getUserId($item);

        $isLeave = $this->userRepository->isUserLeave($userId);
        $leaveTime = $isLeave?->leave_time ?? $isLeave?->pivot?->leave_time;
        if(!$isLeave){
            return response()->json([
                "token" => $response->json("token"),
                "item" => $response->json("item"),
                "name" => $response->json("name"),
                "isLeave" => false,
            ], 200);
        }
        return response()->json([
                "token" => $response->json("token"),
                "item" => $response->json("item"),
                "name" => $response->json("name"),
                "isLeave" => true,
                "dateLeave" => $leaveTime ? Carbon::parse($isLeave->leave_time)->toIso8601String() : null,
            ], 200);
    }
}