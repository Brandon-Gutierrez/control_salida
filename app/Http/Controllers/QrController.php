<?php
namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;

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
        $user = $request->input('user');
        $qrStatus = Redis::get($token);

        //Validar en redis
        if(!$qrStatus || $qrStatus !== 'qr_libre') {
            return response()->json([
                'status' => 'ERROR',
                'message' => 'QR invalido o ya escaneado'
                ], 400);
        }
        Redis::setex($token, 180, 'qr_en_uso');

        //Verifica si el usuario tiene un registro de salida sin retorno
        $isLeave = DB::table('leave_user')
            ->where('user_id', $user)
            ->whereNull('return_time')
            ->first();
        
        //Si el usuario no tiene retorno, actualizamos el retorno
        if($isLeave)
        {
            DB::table('leave_user')
                ->where('id', $isLeave->id)
                ->update(['return_time' => now()]);

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