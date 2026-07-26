<?php

namespace App\Services\Chatbot;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Enum\ChatbotToolGroup;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Resolves the chatbot configuration from the settings table, falling back to
 * the values in config/panel.php.
 *
 * Settings are read lazily rather than in the constructor: the container may
 * resolve this service while registering console commands, which happens before
 * the settings table exists on a fresh installation.
 */
class ChatbotSettings
{
    public const KEY_PREFIX = 'settings::panel:chatbot:';

    private bool $loaded = false;

    /** @var array<string, mixed> */
    private array $values = [];

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private ConfigRepository $config,
        private Encrypter $encrypter,
    ) {}

    /**
     * The setting keys owned by the chatbot, without the `settings::` prefix.
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return [
            'panel:chatbot:enabled',
            'panel:chatbot:base_url',
            'panel:chatbot:api_key',
            'panel:chatbot:model',
            'panel:chatbot:temperature',
            'panel:chatbot:max_tokens',
            'panel:chatbot:max_iterations',
            'panel:chatbot:history_limit',
            'panel:chatbot:context_tokens',
            'panel:chatbot:compaction',
            'panel:chatbot:timeout',
            'panel:chatbot:require_confirmation',
            'panel:chatbot:system_prompt',
            'panel:chatbot:tool_groups',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->bool('enabled') && $this->baseUrl() !== '' && $this->apiKey() !== '';
    }

    /**
     * The API root of the OpenAI-compatible provider, without a trailing slash.
     */
    public function baseUrl(): string
    {
        return rtrim(trim((string) $this->get('base_url', '')), '/');
    }

    public function apiKey(): string
    {
        $key = (string) $this->get('api_key', '');

        if ($key === '') {
            return '';
        }

        try {
            return (string) $this->encrypter->decrypt($key);
        } catch (\Throwable) {
            // The value was stored before encryption was applied, or with a
            // different application key. Use it as-is and let the provider
            // reject it if it is genuinely invalid.
            return $key;
        }
    }

    public function model(): string
    {
        $model = trim((string) $this->get('model', ''));

        return $model !== '' ? $model : 'gpt-4o-mini';
    }

    public function temperature(): float
    {
        return (float) $this->get('temperature', 0.2);
    }

    public function maxTokens(): int
    {
        return max(1, (int) $this->get('max_tokens', 1024));
    }

    /**
     * How many times the model may call tools before we force it to answer.
     */
    public function maxIterations(): int
    {
        return max(1, min(25, (int) $this->get('max_iterations', 8)));
    }

    /**
     * Hard ceiling on how many stored messages may be replayed, regardless of
     * the token budget. Acts as a backstop, not the primary limit.
     */
    public function historyLimit(): int
    {
        return max(2, (int) $this->get('history_limit', 30));
    }

    /**
     * Approximate token budget for the replayed conversation. This is the real
     * limit: message count is a poor proxy when a single tool result can be
     * larger than fifty chat turns.
     */
    public function contextTokens(): int
    {
        return max(2000, (int) $this->get('context_tokens', 24000));
    }

    /**
     * Whether messages pushed out of the budget are summarized rather than
     * silently dropped. Costs one extra provider call when the window rolls.
     */
    public function compactionEnabled(): bool
    {
        return $this->bool('compaction', true);
    }

    public function timeout(): int
    {
        return max(5, (int) $this->get('timeout', 120));
    }

    public function requiresConfirmation(): bool
    {
        return $this->bool('require_confirmation', true);
    }

    public function systemPrompt(): ?string
    {
        $prompt = trim((string) $this->get('system_prompt', ''));

        return $prompt !== '' ? $prompt : null;
    }

    /**
     * @return string[]
     */
    public function enabledToolGroups(): array
    {
        $groups = $this->get('tool_groups');

        if (is_string($groups)) {
            $groups = json_decode($groups, true);
        }

        if (! is_array($groups)) {
            return ChatbotToolGroup::defaults();
        }

        $valid = array_column(ChatbotToolGroup::cases(), 'value');

        return array_values(array_intersect(array_map('strval', $groups), $valid));
    }

    public function isToolGroupEnabled(ChatbotToolGroup $group): bool
    {
        return in_array($group->value, $this->enabledToolGroups(), true);
    }

    private function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_string($value)) {
            return $value === 'true' || $value === '1';
        }

        return (bool) $value;
    }

    private function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return $this->values[$key] ?? $default;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        foreach (self::keys() as $key) {
            $short = str_replace('panel:chatbot:', '', $key);

            try {
                $value = $this->settings->get('settings::'.$key);
            } catch (\Throwable) {
                $value = null;
            }

            $this->values[$short] = $value ?? $this->config->get('panel.chatbot.'.$short);
        }
    }
}
