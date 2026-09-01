<?php
namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Repositories\UserRepository;

class QrController extends Controller
{
    protected UserRepository $userRepository;
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function generateDynamicQr(Request $request)
    {
        $premiseName = $request->query("name");
        $token = $premiseName . Str::uuid();

        Redis::setex($token, 60000, 'qr_libre');
        return response()->json([
            'token' => $token,
            'TTL' => 60000,
            'status' => 'qr_libre'],
            200); 
    } //falta retornar error

    public function getStatus(Request $request)
    {
        $token = $request->input('token');
        $item = $request->input('item');
        $qrStatus = Redis::get($token);

        //Validar en redis
        if(!$qrStatus) {
            return response()->json([
                'status' => 1,
                'message' => 'QR invalido o ya escaneado'
                ], 400);
        }
        //Redis::setex($token, 180, 'qr_en_uso');
        
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 

        if(!$response || $response->json('status') == 1)
        {
            return response()->json([
                "status" => 1,
                "message" => "Usuario no identificado, intentelo nuevamente",
                "data" => $response->json(),
            ], 400);
        }
        
        $userId = $this->userRepository->getUserId($item);
        //Verifica si el usuario tiene un registro de salida sin retorno
        $isLeave = $this->userRepository->isUserLeave($userId);
        
        //Si el usuario no tiene retorno, actualizamos el retorno
        if($isLeave)
        {
            $this->userRepository->registerReturn($userId);

            //Redis::del($token);

            return response()->json([
                'status' => 0,
                'action' => 'showHome',
                'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                ], 200);
        }
        //Sino se muestran los motivos de salida
        //Redis::expire($token, 180);
        return response()->json([
            'status' => 0, 
            'action' => 'showReasons', 
            'message' => 'QR escaneado correctamente'
            ], 200);
    }
}