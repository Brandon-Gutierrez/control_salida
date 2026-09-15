<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;

use App\Repositories\UserRepository;


class UserController extends Controller
{
    //Obtener los datos del usuario
    public function getUserData(Request $request) : JsonResponse
    {
        $external_id = $request->query("ecternal_identifier");
        //Si el identificador esta vacio
        if(!$external_id){
            return response()->json([
                "status" => "ERROR",
                "message" => "No se pudo obtener la información"
            ], 400);
        }
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
            ])->get(env('API_GETCOM'), [
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