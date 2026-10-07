<?php

namespace App\Http\Controllers;

use App\Repositories\RoleRepository;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    /** Catálogo de roles disponibles (EMPLOYEE, ADMIN, MANAGE_PREMISE). */
    public function index(RoleRepository $roles): JsonResponse
    {
        return response()->json([
            'status' => 0,
            'data' => $roles->allOrderedByName(),
        ]);
    }
}
