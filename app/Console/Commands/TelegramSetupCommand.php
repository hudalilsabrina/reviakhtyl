<?php

namespace App\Console\Commands;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Services\Telegram\TelegramBotService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class TelegramSetupCommand extends Command
{
    protected $signature = 'telegram:setup
                            {--test : Test bot connection without setting webhook}
                            {--info : Display current webhook configuration}';

    protected $description = 'Configure Telegram bot webhook and test connection';

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private TelegramBotService $telegram
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->telegram->isEnabled()) {
            $this->error('Telegram bot is not enabled. Enable it in Admin → Settings → Telegram.');

            return Command::FAILURE;
        }

        if ($this->option('info')) {
            return $this->showWebhookInfo();
        }

        if ($this->option('test')) {
            return $this->testConnection();
        }

        return $this->setupWebhook();
    }

    private function testConnection(): int
    {
        $this->info('Testing bot connection...');

        $botInfo = $this->telegram->getMe();
        if (! $botInfo) {
            $this->error('Failed to connect to Telegram API. Check your bot token.');

            return Command::FAILURE;
        }

        $this->info('✅ Bot connection successful!');
        $this->table(
            ['Field', 'Value'],
            [
                ['Bot ID', $botInfo['id']],
                ['Bot Username', '@'.$botInfo['username']],
                ['Bot Name', $botInfo['first_name']],
                ['Can Join Groups', $botInfo['can_join_groups'] ? 'Yes' : 'No'],
                ['Can Read Messages', $botInfo['can_read_all_group_messages'] ? 'Yes' : 'No'],
            ]
        );

        return Command::SUCCESS;
    }

    private function showWebhookInfo(): int
    {
        $this->info('Fetching webhook info...');

        $info = $this->telegram->getWebhookInfo();
        if (! $info) {
            $this->error('Failed to get webhook info.');

            return Command::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Webhook URL', $info['url'] ?: '(not set)'],
                ['Has Custom Certificate', $info['has_custom_certificate'] ? 'Yes' : 'No'],
                ['Pending Updates', $info['pending_update_count'] ?? 0],
                ['Last Error Date', ! empty($info['last_error_date']) ? date('Y-m-d H:i:s', $info['last_error_date']) : 'None'],
                ['Last Error Message', $info['last_error_message'] ?? 'None'],
                ['Max Connections', $info['max_connections'] ?? 40],
            ]
        );

        return Command::SUCCESS;
    }

    private function setupWebhook(): int
    {
        $appUrl = config('app.url');
        if (empty($appUrl) || $appUrl === 'http://localhost') {
            $this->error('APP_URL is not configured properly. Set it to your panel URL in .env');

            return Command::FAILURE;
        }

        $webhookUrl = $appUrl.'/api/public/telegram/webhook';

        $this->info("Setting webhook to: {$webhookUrl}");

        // Ensure webhook secret exists
        $webhookSecret = $this->settings->get('settings::panel:telegram:webhook_secret', null);
        if (empty($webhookSecret)) {
            $webhookSecret = Str::random(32);
            $this->settings->set('settings::panel:telegram:webhook_secret', $webhookSecret);
            $this->info('✅ Generated webhook secret');
        }

        $success = $this->telegram->setWebhook($webhookUrl, $webhookSecret);

        if (! $success) {
            $this->error('Failed to set webhook. Check logs for details.');

            return Command::FAILURE;
        }

        $this->info('✅ Webhook set successfully!');

        // Verify webhook
        $info = $this->telegram->getWebhookInfo();
        if ($info && $info['url'] === $webhookUrl) {
            $this->newLine();
            $this->info('Webhook verification:');
            $this->line("  URL: {$info['url']}");
            $this->line('  Status: Active');
            $this->newLine();
            $this->info('Users can now link their Telegram accounts via Account → Telegram');
        } else {
            $this->warn('Webhook was set but verification failed. Check manually with --info');
        }

        return Command::SUCCESS;
    }
}
