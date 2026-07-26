<?php

namespace App\Http\Controllers\Api\Client;

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
            'linked' => ! empty($user->telegram_id),
            'telegram_id' => $user->telegram_id,
        ]);
    }

    public function generateToken(Request $request): JsonResponse
    {
        if (! $this->telegram->isEnabled()) {
            return new JsonResponse(['error' => 'Telegram bot is not enabled'], 403);
        }

        $user = $request->user();

        if ($user->telegram_id) {
            return new JsonResponse(['error' => 'Already linked to Telegram'], 400);
        }

        // ponytail: rate limit 3/min, add when spam observed
        $rateLimitKey = "telegram_link_rate:{$user->id}";
        $attempts = cache()->get($rateLimitKey, 0);
        if ($attempts >= 3) {
            return new JsonResponse(['error' => 'Rate limit exceeded. Try again in 1 minute.'], 429);
        }

        cache()->put($rateLimitKey, $attempts + 1, now()->addMinute());

        $token = Str::random(32);
        cache()->put("telegram_auth_{$token}", $user->id, now()->addMinutes(10));

        $botUsername = $this->telegram->getBotUsername();

        return new JsonResponse([
            'token' => $token,
            'link' => "https://t.me/{$botUsername}?start={$token}",
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ]);
    }

    public function unlink(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->telegram_id) {
            return new JsonResponse(['error' => 'Not linked to Telegram'], 400);
        }

        $user->update(['telegram_id' => null]);

        return new JsonResponse(['success' => true]);
    }
}
