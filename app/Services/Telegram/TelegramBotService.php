<?php

namespace App\Services\Telegram;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBotService
{
    protected ?string $token;
    protected string $apiUrl;

    public function __construct()
    {
        $this->token = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->token}";
    }

    public function isEnabled(): bool
    {
        return !empty($this->token) && config('services.telegram.enabled', false);
    }

    public function sendMessage(string $chatId, string $text): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $response = Http::post("{$this->apiUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram sendMessage failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function handleWebhook(array $update): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = $message['text'] ?? '';
        $telegramId = (string) $message['from']['id'];

        if (!$chatId) {
            return;
        }

        if (str_starts_with($text, '/start')) {
            $this->handleStart($chatId, $telegramId, $text);
        } elseif (str_starts_with($text, '/unlink')) {
            $this->handleUnlink($chatId, $telegramId);
        } else {
            $this->sendMessage($chatId, "Unknown command. Available commands:\n/start - Link your account\n/unlink - Unlink your account");
        }
    }

    protected function handleStart(string $chatId, string $telegramId, string $text): void
    {
        $user = User::where('telegram_id', $telegramId)->first();

        if ($user) {
            $this->sendMessage($chatId, "✅ Your Telegram account is already linked to: *{$user->email}*");
            return;
        }

        // Extract token from /start command (format: /start TOKEN)
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            $linkToken = Str::random(32);
            cache()->put("telegram_link_{$linkToken}", $telegramId, now()->addMinutes(10));

            $linkUrl = config('app.url') . "/account/telegram/link?token={$linkToken}";
            $this->sendMessage($chatId, "🔗 To link your Telegram account:\n\n1. Click this link: {$linkUrl}\n2. Log in to your panel account\n3. Confirm the link\n\n⏱ This link expires in 10 minutes.");
            return;
        }

        $token = trim($parts[1]);
        $userId = cache()->get("telegram_auth_{$token}");

        if (!$userId) {
            $this->sendMessage($chatId, "❌ Invalid or expired token. Please generate a new link from your panel account settings.");
            return;
        }

        $user = User::find($userId);
        if (!$user) {
            $this->sendMessage($chatId, "❌ User not found.");
            return;
        }

        if ($user->telegram_id) {
            $this->sendMessage($chatId, "❌ Your account is already linked to another Telegram account.");
            return;
        }

        $user->update(['telegram_id' => $telegramId]);
        cache()->forget("telegram_auth_{$token}");

        $this->sendMessage($chatId, "✅ Successfully linked to: *{$user->email}*\n\nYou can now receive notifications and control your servers via Telegram.");
    }

    protected function handleUnlink(string $chatId, string $telegramId): void
    {
        $user = User::where('telegram_id', $telegramId)->first();

        if (!$user) {
            $this->sendMessage($chatId, "❌ Your Telegram account is not linked to any panel account.");
            return;
        }

        $user->update(['telegram_id' => null]);
        $this->sendMessage($chatId, "✅ Successfully unlinked from: *{$user->email}*");
    }

    public function setWebhook(string $url): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $response = Http::post("{$this->apiUrl}/setWebhook", [
                'url' => $url,
            ]);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram setWebhook failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
