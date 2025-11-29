<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WebhookController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('holds', [HoldController::class, 'store']);
Route::post('orders', [OrderController::class, 'store']);
Route::post('webhooks', [WebhookController::class, 'handle']);
