<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WebPushController;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webpush/subscribe', [WebPushController::class, 'subscribe']);
Route::post('/webpush/send', [WebPushController::class, 'send']);
