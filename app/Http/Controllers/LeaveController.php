<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Repositories\LeavePremiseRepository;
use App\Repositories\UserRepository;


class LeaveController extends Controller
{
    //constructor 
    protected LeavePremiseRepository $leavePremiseRepository;
      protected UserRepository $userRepository;
    public function __construct(LeavePremiseRepository $leavePremiseRepository, UserRepository $userRepository)
    {
        $this->leavePremiseRepository = $leavePremiseRepository;
        $this->userRepository = $userRepository;
    }


    //Obtener las salidas de un predio
    public function getLeavesOfPremise(Request $request)
    {
        $premiseId = $request->integer("premise");
        $data = $this->leavePremiseRepository->getLeaves($premiseId);
        return response()->json(['reasons' => $data], 200);
    }

    //Confirmar la salida de un usuario
    public function confirmLeave(Request $request)
    {
        $qrData = $request->input("qrData");
        $item = $request->input("item");
        $premise = $request->input("premise");
        $reason = $request->input("reason");
        $qrStatus = Redis::get($qrData);

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
            ], 400);
        }
        
        //Obtener el id del usuario en la base de datos local
        $userId = $this->userRepository->getUserId($item);

        $leave_premise_id = $this->leavePremiseRepository->findALeavePremise($premise, $reason);

        //Registrar la salida temporal del usuario
        $this->userRepository->registerLeave($userId, $leave_premise_id);

        //Redis::del($token);
        return response()->json([
            "status" => "SUCCESS",
            "message" => "Salida temporal registrada correctamente"
        ]);
    }
}
