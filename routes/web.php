<?php
use App\Http\Controllers\QrController;

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
