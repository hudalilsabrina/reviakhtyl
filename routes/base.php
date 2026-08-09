<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Base;
use App\Http\Middleware\AdminAuthenticate;
use App\Http\Middleware\RequireTwoFactorAuthentication;
use Illuminate\Support\Facades\Route;

Route::get('/', [Base\IndexController::class, 'index'])->name('index')->fallback();
Route::get('/account', [Base\IndexController::class, 'index'])
    ->withoutMiddleware(RequireTwoFactorAuthentication::class)
    ->name('account');

Route::get('/locales/locale.json', Base\LocaleController::class)
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class])
    ->where('namespace', '.*');

Route::get('/locales/list.json', [Base\LocaleController::class, 'list'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class]);

Route::get('/manifest.json', [Base\PwaManifestController::class, 'index'])
    ->withoutMiddleware(['auth', RequireTwoFactorAuthentication::class]);

Route::get('/status/{server}', [Base\IndexController::class, 'index'])
    ->withoutMiddleware(['auth', 'auth.session', RequireTwoFactorAuthentication::class]);

Route::prefix('preview')
    ->middleware(['auth', AdminAuthenticate::class])
    ->group(function () {
        Route::get('/404', fn () => response()->view('errors.404', [], 404));
        Route::get('/403', fn () => response()->view('errors.403', [], 403));
        Route::get('/500', fn () => response()->view('errors.500', [], 500));
    });

Route::prefix('admin/chat')
    ->middleware(['auth', AdminAuthenticate::class, 'throttle:api.chatbot'])
    ->group(function () {
        Route::get('/config', [Admin\ChatbotController::class, 'config']);
        Route::get('/conversations', [Admin\ChatbotController::class, 'index']);
        Route::post('/conversations', [Admin\ChatbotController::class, 'store']);
        Route::get('/conversations/{chatbotConversation}', [Admin\ChatbotController::class, 'view']);
        Route::delete('/conversations/{chatbotConversation}', [Admin\ChatbotController::class, 'delete']);
        Route::post('/conversations/{chatbotConversation}/messages', [Admin\ChatbotController::class, 'message']);
        Route::post('/conversations/{chatbotConversation}/messages/stream', [Admin\ChatbotController::class, 'stream']);
        Route::post('/conversations/{chatbotConversation}/confirm', [Admin\ChatbotController::class, 'confirm']);
        Route::post('/conversations/{chatbotConversation}/confirm/stream', [Admin\ChatbotController::class, 'confirmStream']);
    });

Route::get('/{react}', [Base\IndexController::class, 'index'])
    ->where('react', '^(?!(\/)?(api|auth|admin|preview|designify|daemon)).+');
