<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;


class LeaveController extends Controller
{
    //Confirmar la salida de un usuario
    public function ConfirmLeave(Request $request)
    {
        $token = $request->input('token_qr');
        $leaveId = $request->input('leave_id');
        $user = $request->input();

        //Validar en redis
        $qrStatus = Redis::get($token);
        if(!$qrStatus || $qrStatus !== 'qr_en_espera') {
            return response()->json(['message' => 'QR inválido o ya escaneado'], 400);
        }
        //Actualizar la hora de salida en la tabla pivot leave_user
        DB::table('leave_user')->insert([
            'user_id' => $user->id,
            'leave_id'=> $leaveId,
            'leave_time' => now(),
            'return_time' => null,
        ]);
        //Eliminar el token de Redis
        Redis::del($token);
        return response()->json(['message' => 'Registro de salida exitoso'], 200);
    }
}
