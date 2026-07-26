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
        if (!$this->telegram->isEnabled()) {
            return response()->json(['error' => 'Telegram bot is not enabled'], 403);
        }

        $this->telegram->handleWebhook($request->all());

        return response()->json(['ok' => true]);
    }
}
