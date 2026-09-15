<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ThirdPartyService
{
    public function authenticate(string $username, string $password): ?array {
        
        $response = Http::withHeaders([
            'keysoftware' => env('KEY_SOFTWARE'),
            'Content-Type' => 'application/json',
            ])->post(env('API_LOGIN'), [ 
            'username' => $username,
            'password' => $password
        ]);
        //Verifica si hay un error en los datos 
        if ($response->failed() || $response->json("status") == 1)
        {
            //retorna esetado de error
            return null;
        }
        return [
            'external_identifier' => $response->json("token"),
            'name' => $response->json("name"),
            'item' => $response->json("item"),
        ];
    }
}