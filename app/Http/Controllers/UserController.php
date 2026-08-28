<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;


class UserController extends Controller
{
    public function login(Request $request)
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
        if (!$response || $response->json("status") == 1)
        {
            //retorna esetado de error
            return response()->json($response->json(), 400);
        }
        
        //Verifica si el usuario ya existe en la base de datos local
        // si no existe lo inserta
        $data =DB::table("users")
            ->where(["username" => $response->json("username")])       
            ->orWhere(["item" => $response->json("item")])
            ->first();
        //Si no hay datos los guarda
        if (!$data){
            DB::table("users")->insert([
                "username" => $response->json("username"),
                "name"=> $response->json("name"),
                "item" => $response->json("item"),
                "created_at" => now()
            ]);
        }
        //retorna los datos necesarios
        return response()->json([
            "token" => $response->json("token"),
            "item" => $response->json("item"),
            "name" => $response->json("name"),
            ], 200);
    }

    public function getUser(Request $request)
    {
        $item = $request->input("item");
        if(!$item){
            return response()->json([
                "status" => 0,
                "message" => "No se puedo realizar la conexión"
            ], 400);
        }
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 
        
        if(!$response || $response->json("status") == 1)
        {
            return response()->json($response->json(), 400);
        }

        $user = DB::table("users")
            ->where('item', $item)
            ->first();

        $isLeave = DB::table('leave_user')
            ->where('user_id', $user->id)
            ->whereNull('return_time')
            ->first();
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
                "dateLeave" => $isLeave->leave_time ? Carbon::parse($isLeave->leave_time)->toIso8601String() : null,
            ], 200);
    }
    //funcion para mostrar el estado del usuario y devolver datos de valor
}