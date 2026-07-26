<?php

namespace App\Services\Telegram;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBotService
{
    protected ?string $token = null;

    protected ?string $webhookSecret = null;

    protected bool $settingsLoaded = false;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Encrypter $encrypter
    ) {}

    /**
     * Read the bot credentials from the settings table, at most once per instance.
     *
     * This is deliberately not done in the constructor: the container resolves this
     * service while registering the console commands that depend on it, which happens
     * before the settings table exists on a fresh installation, and would take every
     * artisan command down with it — including the migration that creates the table.
     */
    protected function loadSettings(): void
    {
        if ($this->settingsLoaded) {
            return;
        }

        $this->settingsLoaded = true;

        $token = $this->settings->get('settings::panel:telegram:bot_token', null);

        if (! empty($token)) {
            try {
                $token = $this->encrypter->decrypt($token);
            } catch (\Throwable) {
            }
        }

        $this->token = $token;
        $this->webhookSecret = $this->settings->get('settings::panel:telegram:webhook_secret', null);
    }

    protected function getToken(): ?string
    {
        $this->loadSettings();

        return $this->token;
    }

    protected function apiUrl(): string
    {
        return "https://api.telegram.org/bot{$this->getToken()}";
    }

    public function getWebhookSecret(): ?string
    {
        $this->loadSettings();

        return $this->webhookSecret;
    }

    public function isEnabled(): bool
    {
        if (empty($this->getToken())) {
            return false;
        }

        return $this->settings->get('settings::panel:telegram:enabled', 'false') === 'true';
    }

    public function getBotUsername(): string
    {
        return $this->settings->get('settings::panel:telegram:bot_username', '');
    }

    public function sendMessage(string $chatId, string $text): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $response = Http::post("{$this->apiUrl()}/sendMessage", [
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
        if (! $this->isEnabled()) {
            return;
        }

        $message = $update['message'] ?? null;
        if (! $message) {
            return;
        }

        $chatId = $message['chat']['id'] ?? null;
        $text = $message['text'] ?? '';
        $telegramId = (string) $message['from']['id'];

        if (! $chatId) {
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

            $linkUrl = config('app.url')."/account/telegram/link?token={$linkToken}";
            $this->sendMessage($chatId, "🔗 To link your Telegram account:\n\n1. Click this link: {$linkUrl}\n2. Log in to your panel account\n3. Confirm the link\n\n⏱ This link expires in 10 minutes.");

            return;
        }

        $token = trim($parts[1]);
        $userId = cache()->get("telegram_auth_{$token}");

        if (! $userId) {
            $this->sendMessage($chatId, '❌ Invalid or expired token. Please generate a new link from your panel account settings.');

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            $this->sendMessage($chatId, '❌ User not found.');

            return;
        }

        if ($user->telegram_id) {
            $this->sendMessage($chatId, '❌ Your account is already linked to another Telegram account.');

            return;
        }

        // Check if telegram_id already linked to another user
        $existingUser = User::where('telegram_id', $telegramId)->first();
        if ($existingUser && $existingUser->id !== $user->id) {
            $this->sendMessage($chatId, '❌ This Telegram account is already linked to another panel account.');

            return;
        }

        $user->update(['telegram_id' => $telegramId]);
        cache()->forget("telegram_auth_{$token}");

        $this->sendMessage($chatId, "✅ Successfully linked to: *{$user->email}*\n\nYou can now receive notifications and control your servers via Telegram.");
    }

    protected function handleUnlink(string $chatId, string $telegramId): void
    {
        $user = User::where('telegram_id', $telegramId)->first();

        if (! $user) {
            $this->sendMessage($chatId, '❌ Your Telegram account is not linked to any panel account.');

            return;
        }

        $user->update(['telegram_id' => null]);
        $this->sendMessage($chatId, "✅ Successfully unlinked from: *{$user->email}*");
    }

    public function setWebhook(string $url, ?string $secretToken = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $params = ['url' => $url];
            if ($secretToken) {
                $params['secret_token'] = $secretToken;
            }

            $response = Http::post("{$this->apiUrl()}/setWebhook", $params);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Telegram setWebhook failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function getWebhookInfo(): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $response = Http::get("{$this->apiUrl()}/getWebhookInfo");

            return $response->successful() ? $response->json('result') : null;
        } catch (\Exception $e) {
            Log::error('Telegram getWebhookInfo failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getMe(): ?array
    {
        if (! $this->getToken()) {
            return null;
        }

        try {
            $response = Http::get("{$this->apiUrl()}/getMe");

            return $response->successful() ? $response->json('result') : null;
        } catch (\Exception $e) {
            Log::error('Telegram getMe failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
