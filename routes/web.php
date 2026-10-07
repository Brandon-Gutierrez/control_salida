<?php
use App\Http\Controllers\QrController;

use Illuminate\Support\Facades\Route;

// Define el comportamiento de esta ruta.
Route::get('/', function () {
    return view('welcome');
});
