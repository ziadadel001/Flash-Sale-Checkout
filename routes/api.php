<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HoldController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\ProductController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');



Route::prefix('products')->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('{product}', [ProductController::class, 'show']);
});


Route::post('holds', [HoldController::class, 'store'])->middleware('throttle:20,1');
Route::post('orders', [OrderController::class, 'store'])->middleware('throttle:20,1');
Route::post('webhooks', [WebhookController::class, 'handle']);
