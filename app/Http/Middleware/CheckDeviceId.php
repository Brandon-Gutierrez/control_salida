<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class CheckDeviceId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->headers->has("DeviceId"))
        {
            $header = $request->header("DeviceId");
            if (!empty($header))
            {
                $deviceId = User::where([
                "username" => response()->json("username"),
                "item" => response()->json("item"),
                ])->value('device_id');
                if ($request->header("DeviceId") === $deviceId)
                {
                return $next($request);
                }
            }
        }
        return response()->json([
            "status" => 1,
            "message" => "Dispositivo de usuario no identificado"
        ], 401);
    }
}
