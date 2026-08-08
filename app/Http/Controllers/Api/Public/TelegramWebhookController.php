<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(private TelegramBotService $telegram) {}

    public function __invoke(Request $request)
    {
        if (! $this->telegram->isEnabled()) {
            return response()->json(['error' => 'Telegram bot is not enabled'], 403);
        }

        // Verify webhook signature. The secret is mandatory: without it the
        // endpoint would accept anyone's POST and let them spam the bot.
        $expectedToken = $this->telegram->getWebhookSecret();
        if (! $expectedToken || $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expectedToken) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $this->telegram->handleWebhook($request->all());

        return response()->json(['ok' => true]);
    }
}
