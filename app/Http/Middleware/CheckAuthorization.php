<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Http;

class CheckAuthorization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has("Authorization"))
        {
            $header = $request->header("Authorization");
            if (!empty($header))
            {
                $response = Http::withHeaders([
                'keysoftware' => env('KEY_SOFTWARE'),
                'Content-Type' => 'application/json',
                ])->post(env('API_POSTCOM'), [ 
                'username' => request()->json("username"),
                'password' => request()->json("password"),
                ]);
                $token = $response->json("token");
                if ($request->header("Authorization") === $token)
                {
                return $next($request);
                }
            }
        }
        return response()->json([
            "status" => 1,
            "message" => "Usuario no identificado"
        ], 401);
    }
}
