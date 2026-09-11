<?php
namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Redis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Repositories\UserRepository;
use App\Repositories\PremiseRepository;
use App\Repositories\RecordRepository;

class QrController extends Controller
{
    protected UserRepository $userRepository;
    protected PremiseRepository $premiseRepository;
    protected RecordRepository $recordRepository;

    public function __construct(UserRepository $userRepository, PremiseRepository $premiseRepository, RecordRepository $recordRepository)
    {
        $this->userRepository = $userRepository;
        $this->premiseRepository = $premiseRepository;
        $this->recordRepository = $recordRepository;
    }

    //Genera codigos unicos para cada predio, para la generacion de qr y la identificacion del predio
    public function generateDynamicQr(Request $request)
    {
        $premiseName = $request->query("name");
        $premiseId = $this->premiseRepository->getPremiseId($premiseName);

        if (!$premiseId)
        {
            return response()->json([
                "error" => 0,
                "message" => "Predio no encontrado",
            ], 400);
        }
        $token = $premiseName .'+'. Str::uuid();
        $TTL = 60000;

        Redis::setex($token, $TTL, $premiseId);
        $isStorage = Redis::get($token);

        if (!$isStorage)
        {
            return response()->json([
                'status' => 1,
                'message' => 'Error al generar token del qr'
            ], 400);
        }
        return response()->json([
            'status' => 0,
            'token' => $token,
            'TTL' => $TTL,
            ], 200); 
    }

    //Validar si retorna al mismo predio
    public function fetchUserStatusBeforeQr(Request $request)
    {
        $qrData = $request->input('qrData');
        $item = $request->input('item');
        $qrStatus = Redis::get($qrData);

        //Validar en redis
        if(!$qrStatus) {
            return response()->json([
                'status' => 1,
                'message' => 'QR invalido o vencido'
                ], 400);
        }
        
        $userData = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETEMPLOYEE'), [
                'item' => $item
            ]); 

        //Si el usuario no esta identificado
        if(!$userData || $userData->json('status') == 1)
        {
            return response()->json([
                "status" => 1,
                "message" => "Usuario no identificado, intentelo nuevamente",
                "data" => $userData->json(),
            ], 400);
        }
        //LOGICA CON API
        $date = now()->format('Y-m-d');
        $dataCheckout = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
        ])->post(env('API_GETCHECKOUT'), [
            'in_item' => $item,
            'in_fecha' => $date,
        ]);
        if($dataCheckout->failed())
        {
            return response()->json([
                "status" => 1,
                 "message" => "Error externo"
                 ], 400);
        }
        //Si el usuario no salida, mostramos los motivos
        if($dataCheckout->json('error') === -1 && empty($dataCheckout->json('data')))
        {
            return response()->json([
            'status' => 0, 
            'action' => 'showReasons', 
            'qrData' => $qrData,
            'message' => 'QR escaneado correctamente'
            ], 200);
        }
        //Sino se marca el retorno
            $premiseName = str($qrData)->before('+');
            $isSamePremise = $this->recordRepository->isSamePremise($premiseName);
            if($isSamePremise == false)
            {
                return response()->json([
                    "status" => 1,
                    "message" => "El predio de retorno es diferente al predio de salida"
                ], 403);
            }
            $data = $dataCheckout->json("data");
            $RegisteredCheckout = Http::withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
            ])->post(env('API_REGISTERCHECKOUT'), [
                'in_item' => $item,
                'in_id_solicitud' => $data[0]["id_solicitud"],
            //'in_fecha' => now()->format('Y-m-d H:i:s'),
            ]);
            if($RegisteredCheckout->assertStatus(200) == true)
            {
                return response()->json([
                    'status' => 0,
                    'action' => 'showHome',
                    'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                    ], 200);
            }
            return response()->json([
                    'status' => 1,
                    'message' => 'Error en el registro de retorno'
                    ], 400);
    }
    //LOGICA CON BASE DE DATOS LOCAL
        /*
        $userId = $this->userRepository->getUserId($item);
        //Verifica si el usuario tiene un registro de salida sin retorno
        $isLeave = $this->userRepository->isUserLeave($userId);
        
        //Si el usuario no tiene retorno, actualizamos el retorno
        if($isLeave)
        {
            $premiseName = str($qrData)->before('+');
            $isSamePremise = $this->recordRepository->isSamePremise($premiseName);
            if($isSamePremise == false)
            {
                return response()->json([
                    "status" => 1,
                    "message" => "El predio de retorno es diferente al predio de salida"
                ], 403);
            }

            $this->userRepository->registerReturn($userId);

            return response()->json([
                'status' => 0,
                'action' => 'showHome',
                'message' => 'Bienvenido de regreso, su retorno ha sido registrado correctamente'
                ], 200);
        }
        //Sino se muestran los motivos de salida
        return response()->json([
            'status' => 0, 
            'action' => 'showReasons', 
            'qrData' => $qrData,
            'message' => 'QR escaneado correctamente'
            ], 200);
    }
    */
}