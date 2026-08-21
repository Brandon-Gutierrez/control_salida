<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;


class LeaveController extends Controller
{
    //Confirmar la salida de un usuario
    public function confirmLeave(Request $request)
    {
        $token = $request->input("token");
        $item = $request->input("item");
        $reason = $request->input("reason");
        $qrStatus = Redis::get($token);

        //Validar en redis
        if(!$qrStatus)
        {
            return response()->json([
                "status" => "ERROR",
                "message" => "Tiempo de espera del QR expirado"
            ], 400);
        }

        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 

        if(!$response || $response->json("status") == 1)
        {
            return response()->json([
                "status" => "ERROR",
                "message" => "Usuario no identificado, intentelo nuevamente",
                "data" => $response->json()
            ], 400);
        }

        /*DB::table("leave_user")->insert([
            "user_id" => $item,
            "reason_id" => $reason,
            "leave_time" => now()
        ]);*/
        return response()->json([
            "status" => "SUCCESS",
            "message" => "Salida temporal registrada correctamente"
        ]);
    }
}
