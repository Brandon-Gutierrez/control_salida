<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;


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
            return response()->json([
            "status" => "ERROR",
            "message" => "Datos incorrectos o no existentes",
            "data" => $response->json()
        ], 400);
        }
        
        //Verifica si el usuario ya existe en la base de datos local
        // si no existe lo inserta
        $data =DB::table("users")
            ->where(["username" => $response->json("username")])       
            ->orWhere(["item" => $response->json("item")])
            ->first();
        if (!$data){
            DB::table("users")->insert([
                "username" => $response->json("username"),
                "name"=> $response->json("name"),
                "item" => $response->json("item")
            ]);
        }
        return response()->json([
            "status" => "SUCCESS",                
            "data" => $response->json(),
            ], 200);
    }
}