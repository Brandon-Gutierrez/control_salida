<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\UserRepository;
use App\Repositories\ReasonLeaveRepository;
use App\Repositories\PremiseRepository;
use App\Models\Premise;
use Illuminate\Support\Facades\Log;

class LeaveController extends Controller
{
    //constructor 
    protected ReasonPremiseRepository $reasonPremiseRepository;
    protected UserRepository $userRepository;
    protected ReasonLeaveRepository $reasonLeaveRepository;
    protected PremiseRepository $premiseRepository;

    
    public function __construct(ReasonPremiseRepository $reasonPremiseRepository,
                                UserRepository $userRepository,
                                ReasonLeaveRepository $reasonLeaveRepository,
                                PremiseRepository $premiseRepository) 
    {
        $this->reasonPremiseRepository = $reasonPremiseRepository;
        $this->userRepository = $userRepository;
        $this->reasonLeaveRepository = $reasonLeaveRepository;
        $this->premiseRepository = $premiseRepository;
    }

    //Sincroniza el catalogo de motivos de salida con el servicio externo
    public function syncReasons()
    {
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->post(env('API_GETREASONS'), [ 
            'in_Entidad' => 'MOTIVO_SALIDA',
            'in_nombre_maq' => '',
        ]);
        //Verifica si hay un error en los datos 
        if ($response->failed() || $response->json("data") == null)
        {
            //retorna esetado de error
            return response()->json([
                "status" => 1,
                "message" => "Error al obtener los motivos de salida",
            ], 400);
        }
        $isNewData = $this->reasonLeaveRepository->syncReasons($response['data']);
        if (!$isNewData)
        {
            return response()->json([
                "status" => 0,
                "message" => "No se encontraron motivos de salida nuevas",
            ], 200);
        }
        return response()->json([
            "status" => 0,
            "message" => "Motivos de salida actualizados correctamente",
            "newPremises" => $isNewData,
        ], 200);
    }

    //Obtener los motivos de salida de un predio
    public function premiseReasons(Premise $premise)
    {
        $data = $this->reasonPremiseRepository->getReasonsOfPremise($premise->premise_id);
        return response()->json(['reasons' => $data], 200);
    }

    //Confirmar la salida del usuario autenticado
    public function store(Request $request)
    {
        $data = $request->validate([
            'qrData' => ['required', 'string'],
            'namePremise' => ['required', 'string'],
            'nameReason' => ['required', 'string'],
        ]);
        $qrData = $data["qrData"];
        $item = $request->user()->item;
        $namePremise = $data["namePremise"];
        $nameReason = $data["nameReason"];
        $qrStatus = Redis::get($qrData);

        //Validar en redis
        if(!$qrStatus)
        {
            return response()->json([
                "status" => 1,
                "message" => "Tiempo de espera del QR expirado"
            ], 400);
        }

        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            ])->get(env('API_GETEMPLOYEE'), [
                'item' => $item
            ]); 

        if(!$response || $response->json("status") == 1)
        {
            return response()->json([
                "status" => 1,
                "message" => "Usuario no identificado, intentelo nuevamente",
            ], 400);
        }
        
        //Obtener el id de la raon, y el id de la premisa en la base de datos local
        $reasonId = $this->reasonPremiseRepository->getReasonId($nameReason);
        $premiseId = $this->premiseRepository->getPremiseId($namePremise);

        //Busca el id en la tabla pivote(reason_premise)
        $reason_premise_id = ($premiseId && $reasonId)
            ? $this->reasonPremiseRepository->findAReasonPremise($premiseId, $reasonId)
            : null;
        if (!$reason_premise_id)
        {
            return response()->json([
                "status" => 1,
                "message" => "No se encontro la razon de salida del predio"
            ], 400);
        }

        //Registrar la salida temporal del usuario
        //LOGICA CON API
        $codeReason = $this->reasonLeaveRepository->getCodeReason($nameReason);
        Log::class($codeReason);
        $RegisteredCheckout = Http::withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
            ])->post(env('API_REGISTERCHECKOUT'), [
                'in_item' => $item,
                'in_motivo' => $codeReason,
                //'in_fecha' => now()->format('Y-m-d H:i:s'),
            ]);
            if($RegisteredCheckout->successful())
            {
                return response()->json([
                    'status' => 0,
                    'message' => 'Salida temporal registrada correctamente'
                    ], 200);
            }
            return response()->json([
                    'status' => 1,
                    'message' => 'Error en el registro de salida temporal'
                    ], 400);
        //LOGICA CON BASE DE DATOS LOCAL
        /*
        $isRegisteredLeave = $this->userRepository->registerLeave($userId, $reason_premise_id);
        if(!$isRegisteredLeave)
        {
            return response()->json([
                "status" => 1,
                "message" => "Error al registrar la salida temporal"
            ], 400);
        }
        //Redis::del($token);
        return response()->json([
            "status" => 0,
            "message" => "Salida temporal registrada correctamente"
        ]);
        */
    }
}
