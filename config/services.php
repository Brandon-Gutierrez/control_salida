<?php

// Define las opciones de services.
return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Sistema externo de personal: autenticación, datos del empleado, catálogo
    // de motivos y registro de salidas/retornos.
    'external_api' => [
        'key' => env('KEY_SOFTWARE'),
        'login_url' => env('API_LOGIN'),
        'login_carnet_url' => env('API_LOGINWITHCARNET'),
        'employee_url' => env('API_GETEMPLOYEE'),
        'reasons_url' => env('API_GETREASONS'),
        'checkout_url' => env('API_GETCHECKOUT'),
        'register_checkout_url' => env('API_REGISTERCHECKOUT'),
    ],

];
