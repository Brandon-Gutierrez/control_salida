<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ReasonLeave;
use App\Repositories\PremiseRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PremiseController extends Controller
{
    public function __construct(
        protected PremiseRepository $premiseRepository
    ) {}

    /**
     * Obtiene todos los predios con sus motivos asociados.
     */
    public function index(): JsonResponse
    {
        $premises = $this->premiseRepository->getAllWithReasons();

        return response()->json([
            "status" => 0,
            "data" => $premises,
        ], 200);
    }

    /**
     * Obtiene el catálogo completo de motivos de salida.
     */
    public function getAllReasons(): JsonResponse
    {
        $reasons = ReasonLeave::all()->pluck("name");

        return response()->json([
            "status" => 0,
            "reasons" => $reasons
        ], 200);
    }
    
}