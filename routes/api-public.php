<?php

use App\Http\Controllers\Api\Public\ServerStatusController;
use App\Http\Controllers\Api\Public\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/servers/{server}', ServerStatusController::class);
Route::post('/telegram/webhook', TelegramWebhookController::class);
