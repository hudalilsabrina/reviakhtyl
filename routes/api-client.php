<?php

use App\Enum\ResourceLimit;
use App\Http\Controllers\Api\Client;
use App\Http\Middleware\Activity\AccountSubject;
use App\Http\Middleware\Activity\ServerSubject;
use App\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;
use App\Http\Middleware\Api\Client\Server\ResourceBelongsToServer;
use App\Http\Middleware\RequireTwoFactorAuthentication;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client
|
*/
Route::get('/', [Client\ClientController::class, 'index'])->name('api:client.index');
Route::get('/permissions', [Client\ClientController::class, 'permissions']);
Route::get('/eggs', [Client\ClientController::class, 'eggs'])->name('api:client.eggs');
Route::get('/extensions', [Client\ExtensionsController::class, 'index'])->name('api:client.extensions.index');

Route::prefix('/account')->middleware(AccountSubject::class)->group(function () {
    Route::prefix('/')->withoutMiddleware(RequireTwoFactorAuthentication::class)->group(function () {
        Route::get('/', [Client\AccountController::class, 'index'])->name('api:client.account');
        Route::get('/two-factor', [Client\TwoFactorController::class, 'index']);
        Route::post('/two-factor', [Client\TwoFactorController::class, 'store']);
        Route::post('/two-factor/disable', [Client\TwoFactorController::class, 'delete']);
    });

    Route::put('/email', [Client\AccountController::class, 'updateEmail'])->name('api:client.account.update-email');
    Route::put('/password', [Client\AccountController::class, 'updatePassword'])->name('api:client.account.update-password');
    Route::put('/language', [Client\AccountController::class, 'updateLanguage'])->name('api:client.account.update-language');
    Route::put('/file-editor', [Client\AccountController::class, 'updateEditor'])->name('api:client.account.update-editor');

    Route::get('/activity', Client\ActivityLogController::class)->name('api:client.account.activity');

    Route::get('/social-logins', [Client\SocialLoginController::class, 'index'])->name('api:client.account.social-logins');
    Route::delete('/social-logins/{provider}', [Client\SocialLoginController::class, 'delete'])->name('api:client.account.social-logins.delete');

    Route::prefix('/telegram')->group(function () {
        Route::get('/', [Client\TelegramController::class, 'index']);
        Route::post('/generate-token', [Client\TelegramController::class, 'generateToken']);
        Route::post('/unlink', [Client\TelegramController::class, 'unlink']);
    });

    Route::get('/api-keys', [Client\ApiKeyController::class, 'index']);
    Route::post('/api-keys', [Client\ApiKeyController::class, 'store']);
    Route::delete('/api-keys/{identifier}', [Client\ApiKeyController::class, 'delete']);

    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });

    Route::group(['prefix' => '/categories'], function () {
        Route::get('/', [Client\CategoryController::class, 'index'])->name('api:client.account.categories');
        Route::post('/', [Client\CategoryController::class, 'store']);
        Route::get('/{uuid}', [Client\CategoryController::class, 'show']);
        Route::put('/{uuid}', [Client\CategoryController::class, 'update']);
        Route::post('/reorder', [Client\CategoryController::class, 'reorder']);
        Route::delete('/{uuid}', [Client\CategoryController::class, 'delete']);
    });
});

/*
|--------------------------------------------------------------------------
| Client Control API
|--------------------------------------------------------------------------
|
| Endpoint: /api/client/servers/{server}
|
*/
Route::group([
    'prefix' => '/servers/{server}',
    'middleware' => [
        ServerSubject::class,
        AuthenticateServerAccess::class,
        ResourceBelongsToServer::class,
    ],
], function () {
    Route::get('/', [Client\Servers\ServerController::class, 'index'])->name('api:client:server.view');
    Route::middleware([ResourceLimit::Websocket->middleware()])
        ->get('/websocket', Client\Servers\WebsocketController::class)
        ->name('api:client:server.ws');
    Route::get('/resources', Client\Servers\ResourceUtilizationController::class)->name('api:client:server.resources');
    Route::get('/resources/history', Client\Servers\ResourceHistoryController::class)->name('api:client:server.resources.history');
    Route::get('/resources/history/export', [Client\Servers\ResourceHistoryController::class, 'export'])->name('api:client:server.resources.history.export');
    Route::get('/activity', Client\Servers\ActivityLogController::class)->name('api:client:server.activity');

    Route::post('/command', [Client\Servers\CommandController::class, 'index']);
    Route::post('/power', [Client\Servers\PowerController::class, 'index']);

    Route::group(['prefix' => '/databases'], function () {
        Route::get('/', [Client\Servers\DatabaseController::class, 'index']);
        Route::middleware([ResourceLimit::Database->middleware()])
            ->post('/', [Client\Servers\DatabaseController::class, 'store']);
        Route::post('/{database}/rotate-password', [Client\Servers\DatabaseController::class, 'rotatePassword']);
        Route::delete('/{database}', [Client\Servers\DatabaseController::class, 'delete']);
    });

    Route::group(['prefix' => '/files'], function () {
        Route::get('/list', [Client\Servers\FileController::class, 'directory']);
        Route::get('/contents', [Client\Servers\FileController::class, 'contents']);
        Route::get('/download', [Client\Servers\FileController::class, 'download']);
        Route::put('/rename', [Client\Servers\FileController::class, 'rename']);
        Route::post('/copy', [Client\Servers\FileController::class, 'copy']);
        Route::post('/write', [Client\Servers\FileController::class, 'write']);
        Route::post('/compress', [Client\Servers\FileController::class, 'compress']);
        Route::post('/decompress', [Client\Servers\FileController::class, 'decompress']);
        Route::post('/delete', [Client\Servers\FileController::class, 'delete']);
        Route::post('/create-folder', [Client\Servers\FileController::class, 'create']);
        Route::post('/chmod', [Client\Servers\FileController::class, 'chmod']);
        Route::middleware([ResourceLimit::FilePull->middleware()])
            ->post('/pull', [Client\Servers\FileController::class, 'pull']);
        Route::get('/upload', Client\Servers\FileUploadController::class);
    });

    Route::group(['prefix' => '/schedules'], function () {
        Route::get('/', [Client\Servers\ScheduleController::class, 'index']);
        Route::middleware([ResourceLimit::Schedule->middleware()])
            ->post('/', [Client\Servers\ScheduleController::class, 'store']);
        Route::get('/{schedule}', [Client\Servers\ScheduleController::class, 'view']);
        Route::post('/{schedule}', [Client\Servers\ScheduleController::class, 'update']);
        Route::post('/{schedule}/execute', [Client\Servers\ScheduleController::class, 'execute']);
        Route::delete('/{schedule}', [Client\Servers\ScheduleController::class, 'delete']);

        Route::post('/{schedule}/tasks', [Client\Servers\ScheduleTaskController::class, 'store']);
        Route::post('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'update']);
        Route::delete('/{schedule}/tasks/{task}', [Client\Servers\ScheduleTaskController::class, 'delete']);
    });

    Route::group(['prefix' => '/network'], function () {
        Route::get('/allocations', [Client\Servers\NetworkAllocationController::class, 'index']);
        Route::middleware([ResourceLimit::Allocation->middleware()])
            ->post('/allocations', [Client\Servers\NetworkAllocationController::class, 'store']);
        Route::post('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'update']);
        Route::post('/allocations/{allocation}/primary', [Client\Servers\NetworkAllocationController::class, 'setPrimary']);
        Route::delete('/allocations/{allocation}', [Client\Servers\NetworkAllocationController::class, 'delete']);
    });

    Route::group(['prefix' => '/subdomain'], function () {
        Route::get('/', [Client\Servers\SubdomainController::class, 'index']);
        Route::middleware('throttle:api.subdomain')
            ->post('/', [Client\Servers\SubdomainController::class, 'store']);
        Route::middleware('throttle:api.subdomain.status')
            ->get('/status', [Client\Servers\SubdomainController::class, 'status']);
        Route::delete('/', [Client\Servers\SubdomainController::class, 'delete']);
    });

    Route::group(['prefix' => '/properties'], function () {
        Route::get('/', [Client\Servers\PropertiesController::class, 'index']);
        Route::middleware('throttle:api.properties')
            ->put('/', [Client\Servers\PropertiesController::class, 'update']);
        Route::middleware('throttle:api.properties')
            ->put('/raw', [Client\Servers\PropertiesController::class, 'updateRaw']);
        Route::middleware('throttle:api.properties')
            ->post('/eula', [Client\Servers\PropertiesController::class, 'acceptEula']);
    });

    Route::group(['prefix' => '/splits'], function () {
        Route::get('/', [Client\Servers\SplitController::class, 'index']);
        Route::post('/', [Client\Servers\SplitController::class, 'store']);
        Route::post('/{child}/merge', [Client\Servers\SplitMergeController::class, 'store']);
    });

    Route::group(['prefix' => '/plugins'], function () {
        Route::get('/', [Client\Servers\PluginController::class, 'index']);
        Route::get('/search', [Client\Servers\PluginController::class, 'search']);
        Route::get('/versions', [Client\Servers\PluginController::class, 'versions']);
        Route::middleware('throttle:api.plugins')
            ->get('/untracked', [Client\Servers\PluginController::class, 'untracked']);
        Route::middleware('throttle:api.plugins')
            ->post('/register', [Client\Servers\PluginController::class, 'register']);
        Route::middleware('throttle:api.plugins')
            ->post('/', [Client\Servers\PluginController::class, 'store']);
        Route::middleware('throttle:api.plugins')
            ->post('/{plugin}/update', [Client\Servers\PluginController::class, 'update']);
        Route::middleware('throttle:api.plugins')
            ->post('/{plugin}/link', [Client\Servers\PluginController::class, 'link']);
        Route::post('/{plugin}/toggle', [Client\Servers\PluginController::class, 'toggle']);
        Route::delete('/{plugin}', [Client\Servers\PluginController::class, 'destroy']);
    });

    Route::group(['prefix' => '/mods'], function () {
        Route::get('/', [Client\Servers\ModController::class, 'index']);
        Route::get('/search', [Client\Servers\ModController::class, 'search']);
        Route::get('/versions', [Client\Servers\ModController::class, 'versions']);
        Route::middleware('throttle:api.mods')
            ->get('/untracked', [Client\Servers\ModController::class, 'untracked']);
        Route::middleware('throttle:api.mods')
            ->post('/register', [Client\Servers\ModController::class, 'register']);
        Route::middleware('throttle:api.mods')
            ->post('/', [Client\Servers\ModController::class, 'store']);
        Route::middleware('throttle:api.mods')
            ->post('/{mod}/update', [Client\Servers\ModController::class, 'update']);
        Route::middleware('throttle:api.mods')
            ->post('/{mod}/link', [Client\Servers\ModController::class, 'link']);
        Route::middleware('throttle:api.mods')
            ->post('/bulk/update', [Client\Servers\ModController::class, 'bulkUpdate']);
        Route::middleware('throttle:api.mods')
            ->delete('/bulk', [Client\Servers\ModController::class, 'bulkDestroy']);
        Route::post('/{mod}/toggle', [Client\Servers\ModController::class, 'toggle']);
        Route::delete('/{mod}', [Client\Servers\ModController::class, 'destroy']);
    });

    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\Servers\SubuserController::class, 'index']);
        Route::middleware([ResourceLimit::Subuser->middleware()])
            ->post('/', [Client\Servers\SubuserController::class, 'store']);
        Route::get('/{user}', [Client\Servers\SubuserController::class, 'view']);
        Route::post('/{user}', [Client\Servers\SubuserController::class, 'update']);
        Route::delete('/{user}', [Client\Servers\SubuserController::class, 'delete']);
    });

    Route::group(['prefix' => '/backups'], function () {
        Route::get('/', [Client\Servers\BackupController::class, 'index']);
        Route::post('/', [Client\Servers\BackupController::class, 'store']);
        Route::get('/{backup}', [Client\Servers\BackupController::class, 'view']);
        Route::get('/{backup}/download', [Client\Servers\BackupController::class, 'download']);
        Route::post('/{backup}/lock', [Client\Servers\BackupController::class, 'toggleLock']);
        Route::middleware([ResourceLimit::Backup->middleware()])
            ->post('/{backup}/restore', [Client\Servers\BackupController::class, 'restore']);
        Route::delete('/{backup}', [Client\Servers\BackupController::class, 'delete']);
    });

    Route::group(['prefix' => '/startup'], function () {
        Route::get('/', [Client\Servers\StartupController::class, 'index']);
        Route::put('/variable', [Client\Servers\StartupController::class, 'update']);
        Route::put('/parts', [Client\Servers\StartupController::class, 'updateParts']);
    });

    Route::group(['prefix' => '/chat'], function () {
        Route::get('/config', [Client\Servers\ChatbotController::class, 'config']);
        Route::get('/conversations', [Client\Servers\ChatbotController::class, 'index']);
        Route::post('/conversations', [Client\Servers\ChatbotController::class, 'store']);
        // The parameter is named for the model so that scopeBindings() resolves it
        // through Server::chatbotConversations().
        Route::get('/conversations/{chatbotConversation}', [Client\Servers\ChatbotController::class, 'view']);
        Route::delete('/conversations/{chatbotConversation}', [Client\Servers\ChatbotController::class, 'delete']);

        // Both of these can trigger several paid provider calls each.
        Route::middleware('throttle:api.chatbot')->group(function () {
            Route::post('/conversations/{chatbotConversation}/messages', [Client\Servers\ChatbotController::class, 'message']);
            Route::post('/conversations/{chatbotConversation}/messages/stream', [Client\Servers\ChatbotController::class, 'stream']);
            Route::post('/conversations/{chatbotConversation}/confirm', [Client\Servers\ChatbotController::class, 'confirm']);
        });
    });

    Route::group(['prefix' => '/settings'], function () {
        Route::post('/rename', [Client\Servers\SettingsController::class, 'rename']);
        Route::post('/reinstall', [Client\Servers\SettingsController::class, 'reinstall']);
        Route::put('/docker-image', [Client\Servers\SettingsController::class, 'dockerImage']);
        Route::put('/category', [Client\Servers\SettingsController::class, 'setCategory'])->name('api:client:server.settings.category');
    });
});
