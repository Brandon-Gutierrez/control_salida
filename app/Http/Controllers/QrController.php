<?php
namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class QrController extends Controller
{
    public function generateDynamicQr()
    {
        $token = 'qr_comteco' . Str::uuid();

        Redis::setex($token, 60, 'qr_libre');
        return response()->json(['token' => $token, 'TTL' => 60, 'status' => 'qr_libre'], 200); 
    } //falta retornar error

    public function getStatus(Request $request)
    {
        $token = $request->input('token');
        $userToken = $request->input('userToken');
        $item = $request->input('item');
        $qrStatus = Redis::get($token);

        //Validar en redis
        if(!$qrStatus || $qrStatus !== 'qr_libre') {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'QR invalido o ya escaneado'
                ], 400);
        }
        Redis::setex($token, 180, 'qr_en_uso');
        
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 

        if(!$response || $response->json("status") == 1 || $userToken !== $response->json("token"))
        {
            return response()->json([
                "status" => "ERROR",
                "message" => "Usuario no identificado, intentelo nuevamente",
            ], 400);
        }
        
        $user = DB::table("users")
            ->where('item', $item)
            ->first();
        //Verifica si el usuario tiene un registro de salida sin retorno
        $isLeave = DB::table('leave_user')
            ->where('user_id', $user->id)
            ->whereNull('return_time')
            ->first();
        
        //Si el usuario no tiene retorno, actualizamos el retorno
        if($isLeave)
        {
            DB::table('leave_user')
                ->where('id', $isLeave->id)
                ->update([
                    'return_time' => now()]);

            Redis::del($token);

            return response()->json([
                'status' => 'SUCCESS',
                'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                ], 200);
        }
        //Sino se muestran los motivos de salida
        Redis::expire($token, 180);
        return response()->json([
            'status' => 'SUCCESS', 
            'action' => 'showReasons', 
            'message' => 'QR escaneado correctamente'
            ], 200);
    }
}