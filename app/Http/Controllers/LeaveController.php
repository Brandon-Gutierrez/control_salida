<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Http;
use App\Repositories\ReasonPremiseRepository;
use App\Repositories\UserRepository;


class LeaveController extends Controller
{
    //constructor 
    protected ReasonPremiseRepository $reasonPremiseRepository;
    protected UserRepository $userRepository;
    
    public function __construct(ReasonPremiseRepository $reasonPremiseRepository, UserRepository $userRepository)
    {
        $this->reasonPremiseRepository = $reasonPremiseRepository;
        $this->userRepository = $userRepository;
    }


    //Obtener las salidas de un predio
    public function getLeavesOfPremise(Request $request)
    {
        $premiseId = $request->integer("premise");
        $data = $this->reasonPremiseRepository->getLeavesofPremise($premiseId);
        return response()->json(['reasons' => $data], 200);
    }

    //Confirmar la salida de un usuario
    public function confirmLeave(Request $request)
    {
        $qrData = $request->input("qrData");
        $item = $request->input("item");
        $premise = $request->input("premise");
        $name = $request->input("name");
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
            ])->get(env('API_GETCOM'), [
                'item' => $item
            ]); 

        if(!$response || $response->json("status") == 1)
        {
            return response()->json([
                "status" => 1,
                "message" => "Usuario no identificado, intentelo nuevamente",
            ], 400);
        }
        
        //Obtener el id del usuario en la base de datos local
        $userId = $this->userRepository->getUserId($item);

        $reason_premise_id = $this->reasonPremiseRepository->findAreasonPremise($premise, $name);

        //Registrar la salida temporal del usuario
        $isLeaveRegistered = $this->userRepository->registerLeave($userId, $reason_premise_id);
        if(!$isLeaveRegistered)
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
    }
}
