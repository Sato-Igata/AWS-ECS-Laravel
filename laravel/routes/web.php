<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SessionAuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/healthz', function () {
    return response('ok', 200);
})->withoutMiddleware([\App\Http\Middleware\YourDbCheckMiddleware::class]);


// Route::prefix('api')->group(function () {
//     Route::post('/auth/login',  [SessionAuthController::class, 'login']);
//     Route::post('/auth/logout', [SessionAuthController::class, 'logout']);
//     Route::get('/auth/me',      [SessionAuthController::class, 'me'])->middleware('auth');
// });