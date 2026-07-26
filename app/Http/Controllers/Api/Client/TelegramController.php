<?php

namespace App\Http\Controllers\Api\Client;

use App\Models\User;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramController extends ClientApiController
{
    public function __construct(private TelegramBotService $telegram) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return new JsonResponse([
            'linked' => !empty($user->telegram_id),
            'telegram_id' => $user->telegram_id,
        ]);
    }

    public function generateToken(Request $request): JsonResponse
    {
        if (!$this->telegram->isEnabled()) {
            return new JsonResponse(['error' => 'Telegram bot is not enabled'], 403);
        }

        $user = $request->user();

        if ($user->telegram_id) {
            return new JsonResponse(['error' => 'Already linked to Telegram'], 400);
        }

        $token = Str::random(32);
        cache()->put("telegram_auth_{$token}", $user->id, now()->addMinutes(10));

        $botUsername = config('services.telegram.bot_username', 'YourBot');

        return new JsonResponse([
            'token' => $token,
            'link' => "https://t.me/{$botUsername}?start={$token}",
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->telegram_id) {
            return new JsonResponse(['error' => 'Not linked to Telegram'], 400);
        }

        $user->update(['telegram_id' => null]);

        return new JsonResponse(['success' => true]);
    }
}
