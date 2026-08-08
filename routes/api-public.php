<?php

use App\Http\Controllers\Api\Public\ServerStatusController;
use App\Http\Controllers\Api\Public\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api.public.status')->get('/servers/{server}', ServerStatusController::class);
Route::middleware('throttle:api.public.webhook')->post('/telegram/webhook', TelegramWebhookController::class);
