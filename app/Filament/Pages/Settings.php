<?php

namespace App\Filament\Pages;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Enum\ChatbotToolGroup;
use App\Filament\Components\ImageInput;
use App\Models\Egg;
use App\Notifications\MailTested;
use App\Services\Chatbot\OpenAiClient;
use App\Services\Telegram\TelegramBotService;
use App\Traits\Helpers\AvailableLanguages;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\InteractsWithHeaderActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Contracts\HasSchemas;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Settings extends Page implements HasSchemas
{
    use AvailableLanguages;
    use InteractsWithForms;
    use InteractsWithHeaderActions;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-settings';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'tabler-settings-filled';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    protected array $settingKeys = [
        'app:name',
        'app:logo',
        'app:icon',
        'app:locale',
        'app:locale:geolocate',
        'panel:auth:2fa_required',
        'panel:auth:registration_enabled',
        'app:debug',
        'app:pwa',

        'mail:mailers:smtp:host',
        'mail:mailers:smtp:port',
        'mail:mailers:smtp:encryption',
        'mail:mailers:smtp:username',
        'mail:mailers:smtp:password',
        'mail:from:address',
        'mail:from:name',

        'captcha:provider',
        'captcha:recaptcha:secret_key',
        'captcha:recaptcha:website_key',
        'captcha:turnstile:secret_key',
        'captcha:turnstile:site_key',

        'panel:auth:google_enabled',
        'panel:auth:google_client_id',
        'panel:auth:google_client_secret',

        'panel:auth:discord_enabled',
        'panel:auth:discord_client_id',
        'panel:auth:discord_client_secret',

        'panel:auth:github_enabled',
        'panel:auth:github_client_id',
        'panel:auth:github_client_secret',

        'panel:guzzle:timeout',
        'panel:guzzle:connect_timeout',

        'panel:client_features:allocations:enabled',
        'panel:client_features:allocations:range_start',
        'panel:client_features:allocations:range_end',

        'panel:cloudflare:api_token',
        'panel:cloudflare:egg_ids',

        'panel:plugins:egg_ids',
        'panel:mods:egg_ids',
        'panel:properties:egg_ids',

        'panel:telegram:enabled',
        'panel:telegram:bot_token',
        'panel:telegram:bot_username',
        'panel:telegram:webhook_secret',

        'panel:chatbot:enabled',
        'panel:chatbot:base_url',
        'panel:chatbot:api_key',
        'panel:chatbot:model',
        'panel:chatbot:temperature',
        'panel:chatbot:max_tokens',
        'panel:chatbot:max_iterations',
        'panel:chatbot:history_limit',
        'panel:chatbot:timeout',
        'panel:chatbot:require_confirmation',
        'panel:chatbot:system_prompt',
        'panel:chatbot:tool_groups',
    ];

    public function getHeading(): string
    {
        return trans('admin/settings.overview.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('admin/navigation.administration.settings');
    }

    public function getTitle(): string
    {
        return trans('admin/settings.overview.title');
    }

    public function mount(): void
    {
        $settings = app(SettingsRepositoryInterface::class);
        $config = app(ConfigRepository::class);
        $encrypter = app(Encrypter::class);

        $formData = [];

        foreach ($this->settingKeys as $key) {

            $value = $settings->get('settings::'.$key);

            if ($value === null) {
                $value = $config->get(Str::replace(':', '.', $key));
            }

            if (in_array($key, ['mail:mailers:smtp:password', 'panel:cloudflare:api_token', 'panel:telegram:bot_token', 'panel:chatbot:api_key'], true) && ! empty($value)) {
                try {
                    $value = $encrypter->decrypt($value);
                } catch (\Throwable) {
                }
            }

            if ($value === 'true') {
                $value = true;
            }
            if ($value === 'false') {
                $value = false;
            }

            if ($key === 'panel:auth:2fa_required') {
                $value = (int) $value;
            }

            if (in_array($key, ['panel:cloudflare:egg_ids', 'panel:plugins:egg_ids', 'panel:mods:egg_ids', 'panel:properties:egg_ids'], true)) {
                $value = is_array($value) ? $value : ($value ? (json_decode($value, true) ?: []) : []);
            }

            if ($key === 'panel:chatbot:tool_groups') {
                if (! is_array($value)) {
                    $decoded = $value ? json_decode((string) $value, true) : null;
                    $value = is_array($decoded) ? $decoded : ChatbotToolGroup::defaults();
                }

                $valid = array_column(ChatbotToolGroup::cases(), 'value');
                $value = array_values(array_intersect(array_map('strval', $value), $valid));
            }

            $formData[$key] = $value;
        }

        $form = $this->getForm('form');

        if ($form !== null) {
            $form->fill($formData);
        }
    }

    protected function getFormSchema(): array
    {
        return [
            Tabs::make('settings-tabs')
                ->persistTabInQueryString()
                ->tabs([
                    Tab::make('general')
                        ->label(trans('admin/settings.overview.general-title'))
                        ->icon('tabler-settings-2')
                        ->schema($this->generalSettings()),

                    Tab::make('security')
                        ->label(trans('admin/settings.security.title'))
                        ->icon('tabler-shield')
                        ->schema($this->securitySettings()),

                    Tab::make('oauth')
                        ->label('OAuth') // Untranslated because this is a common acronym that stands for "Open Authorization" and is shared across languages.
                        ->icon('tabler-navigation')
                        ->schema($this->oauthSettings()),

                    Tab::make('mail')
                        ->label(trans('admin/settings.mail.title'))
                        ->icon('tabler-mail')
                        ->schema($this->mailSettings()),

                    Tab::make('telegram')
                        ->label('Telegram')
                        ->icon('tabler-brand-telegram')
                        ->schema($this->telegramSettings()),

                    Tab::make('chatbot')
                        ->label('AI Chatbot')
                        ->icon('tabler-robot')
                        ->schema($this->chatbotSettings()),

                    Tab::make('advanced')
                        ->label(trans('admin/settings.advanced.title'))
                        ->icon('tabler-adjustments')
                        ->schema($this->advancedSettings()),
                ]),
        ];
    }

    private function generalSettings(): array
    {
        return [
            Group::make()
                ->columns(4)
                ->schema([
                    TextInput::make('app:name')
                        ->label(trans('admin/settings.overview.app-name'))
                        ->required()
                        ->maxLength(191)
                        ->columnSpan(2),

                    ImageInput::make('app:logo')
                        ->label(trans('admin/settings.overview.app-logo'))
                        ->required()
                        ->maxLength(191)
                        ->columnSpan(1),

                    ImageInput::make('app:icon')
                        ->label(trans('admin/settings.overview.app-icon'))
                        ->required()
                        ->maxLength(191)
                        ->columnSpan(1),
                ]),

            Group::make()
                ->columns(4)
                ->schema([
                    Select::make('app:locale')
                        ->label(trans('admin/settings.overview.default-language'))
                        ->helperText(trans('admin/settings.overview.default-language-hint'))
                        ->options(function () {
                            // Helper to get languages since we can't easily access trait method statically or outside instance context in some cases,
                            // but here we are in instance context.
                            return $this->getAvailableLanguages(true);
                        })
                        ->searchable()
                        ->columnSpan(2)
                        ->native(false),

                    Toggle::make('app:locale:geolocate')
                        ->label(trans('admin/settings.overview.geolocate-language'))
                        ->helperText(trans('admin/settings.overview.geolocate-language-hint'))
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpan(1),
                ]),

            Group::make()
                ->columns(4)
                ->schema([
                    ToggleButtons::make('panel:auth:2fa_required')
                        ->label(trans('admin/settings.overview.2fa'))
                        ->inline()
                        ->icons([
                            0 => 'tabler-lock-off',
                            1 => 'tabler-user-shield',
                            2 => 'tabler-users',
                        ])
                        ->options([
                            0 => trans('admin/settings.overview.not-required'),
                            1 => trans('admin/settings.overview.admin-only'),
                            2 => trans('admin/settings.overview.all-users'),
                        ])
                        ->required()
                        ->columnSpan(2),

                    Toggle::make('app:debug')
                        ->label(trans('admin/settings.overview.debug-mode'))
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpan(1),

                    Toggle::make('app:pwa')
                        ->label(trans('admin/settings.overview.pwa'))
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpan(1),
                ]),
        ];
    }

    private function securitySettings(): array
    {
        return [
            Section::make('CAPTCHA') // Untranslated because this is a common term, it's acronym stands for "Completely Automated Public Turing test to tell Computers and Humans Apart" and is widely recognized as is.
                ->columns(2)
                ->schema([
                    ToggleButtons::make('captcha:provider')
                        ->label(trans('admin/settings.security.provider'))
                        ->options([
                            'disable' => trans('admin/settings.security.disabled'),
                            'recaptcha' => 'reCAPTCHA',
                            'turnstile' => 'Turnstile',
                        ])
                        ->icons([
                            'disable' => 'tabler-lock-access-off',
                            'recaptcha' => 'tabler-brand-google',
                            'turnstile' => 'tabler-brand-cloudflare',
                        ])
                        ->required()
                        ->inline()
                        ->live()
                        ->columnSpan(2),

                    TextInput::make('captcha:recaptcha:website_key')
                        ->label(trans('admin/settings.security.recaptcha-site-key'))
                        ->columnSpan(1)
                        ->visible(fn ($get) => $get('captcha:provider') === 'recaptcha'),

                    TextInput::make('captcha:recaptcha:secret_key')
                        ->label(trans('admin/settings.security.recaptcha-secret-key'))
                        ->columnSpan(1)
                        ->visible(fn ($get) => $get('captcha:provider') === 'recaptcha'),

                    TextInput::make('captcha:turnstile:site_key')
                        ->label(trans('admin/settings.security.turnstile-site-key'))
                        ->columnSpan(1)
                        ->visible(fn ($get) => $get('captcha:provider') === 'turnstile'),

                    TextInput::make('captcha:turnstile:secret_key')
                        ->label(trans('admin/settings.security.turnstile-secret-key'))
                        ->columnSpan(1)
                        ->visible(fn ($get) => $get('captcha:provider') === 'turnstile'),
                ]),

            Section::make(trans('admin/settings.security.registration-title'))
                ->columns(2)
                ->schema([
                    Toggle::make('panel:auth:registration_enabled')
                        ->label(trans('admin/settings.security.registration-enabled'))
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpan(2),
                ]),
        ];
    }

    private function oauthSettings(): array
    {
        return [
            Section::make('Google') // Untranslated because this is a proper noun, it's the name of a company.
                ->columns(3)
                ->icon('tabler-brand-google')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('panel:auth:google_enabled')
                        ->label(trans('admin/settings.oauth.enabled'))
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->inline(false)
                        ->live(),

                    TextInput::make('panel:auth:google_client_id')
                        ->label(trans('admin/settings.oauth.id-label'))
                        ->required(
                            fn ($get) => $get('panel:auth:google_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:google_enabled')
                        ),

                    TextInput::make('panel:auth:google_client_secret')
                        ->label(trans('admin/settings.oauth.secret-label'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn ($get) => $get('panel:auth:google_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:google_enabled')
                        ),
                ]),

            Section::make('Discord') // Untranslated because this is a proper noun, it's the name of a social platform.
                ->columns(3)
                ->icon('tabler-brand-discord')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('panel:auth:discord_enabled')
                        ->label(trans('admin/settings.oauth.enabled'))
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->inline(false)
                        ->live(),

                    TextInput::make('panel:auth:discord_client_id')
                        ->label(trans('admin/settings.oauth.id-label'))
                        ->required(
                            fn ($get) => $get('panel:auth:discord_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:discord_enabled')
                        ),

                    TextInput::make('panel:auth:discord_client_secret')
                        ->label(trans('admin/settings.oauth.secret-label'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn ($get) => $get('panel:auth:discord_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:discord_enabled')
                        ),
                ]),

            Section::make('GitHub') // Untranslated because this is a proper noun, it's the name of a company.
                ->columns(3)
                ->icon('tabler-brand-github')
                ->collapsible()
                ->collapsed()
                ->schema([
                    Toggle::make('panel:auth:github_enabled')
                        ->label(trans('admin/settings.oauth.enabled'))
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->inline(false)
                        ->live(),

                    TextInput::make('panel:auth:github_client_id')
                        ->label(trans('admin/settings.oauth.id-label'))
                        ->required(
                            fn ($get) => $get('panel:auth:github_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:github_enabled')
                        ),

                    TextInput::make('panel:auth:github_client_secret')
                        ->label(trans('admin/settings.oauth.secret-label'))
                        ->password()
                        ->revealable()
                        ->required(
                            fn ($get) => $get('panel:auth:github_enabled')
                        )
                        ->visible(
                            fn ($get) => $get('panel:auth:github_enabled')
                        ),
                ]),
        ];
    }

    private function mailSettings(): array
    {
        return [
            Group::make()
                ->columns(4)
                ->schema([
                    TextInput::make('mail:mailers:smtp:host')
                        ->label(trans('admin/settings.mail.host-label'))
                        ->required()
                        ->columnSpan(2),

                    TextInput::make('mail:mailers:smtp:port')
                        ->label(trans('admin/settings.mail.port-label'))
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(65535)
                        ->columnSpan(1),

                    Select::make('mail:mailers:smtp:encryption')
                        ->label(trans('admin/settings.mail.encryption-label'))
                        ->options([
                            null => trans('admin/settings.mail.none'),
                            'tls' => 'TLS',
                            'ssl' => 'SSL',
                        ])
                        ->columnSpan(1),
                ]),

            Group::make()
                ->columns(4)
                ->schema([
                    TextInput::make('mail:mailers:smtp:username')
                        ->label(trans('admin/settings.mail.username'))
                        ->columnSpan(2),

                    TextInput::make('mail:mailers:smtp:password')
                        ->label(trans('admin/settings.mail.password'))
                        ->password()
                        ->revealable()
                        ->columnSpan(2),
                ]),

            Group::make()
                ->columns(4)
                ->schema([
                    TextInput::make('mail:from:address')
                        ->label(trans('admin/settings.mail.from-label'))
                        ->email()
                        ->required()
                        ->columnSpan(2),

                    TextInput::make('mail:from:name')
                        ->label(trans('admin/settings.mail.from-name-label'))
                        ->required()
                        ->columnSpan(2),
                ]),

            Actions::make([
                Action::make('test_mail')
                    ->label(trans('admin/settings.mail.test-btn'))
                    ->icon('tabler-mail')
                    ->action('testMail')
                    ->color('success'),
            ])->fullWidth(),
        ];
    }

    private function telegramSettings(): array
    {
        return [
            Section::make('Telegram Bot')
                ->description('Configure Telegram bot integration for user notifications and server control.')
                ->columns(2)
                ->schema([
                    Toggle::make('panel:telegram:enabled')
                        ->label('Enable Telegram Bot')
                        ->helperText('Users can link their Telegram accounts to receive notifications.')
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpan(2),

                    TextInput::make('panel:telegram:bot_token')
                        ->label('Bot Token')
                        ->helperText('Get this from @BotFather on Telegram.')
                        ->password()
                        ->revealable()
                        ->required(fn ($get) => $get('panel:telegram:enabled'))
                        ->visible(fn ($get) => $get('panel:telegram:enabled'))
                        ->columnSpan(1),

                    TextInput::make('panel:telegram:bot_username')
                        ->label('Bot Username')
                        ->helperText('Your bot username without @ (e.g., MyPanelBot)')
                        ->required(fn ($get) => $get('panel:telegram:enabled'))
                        ->visible(fn ($get) => $get('panel:telegram:enabled'))
                        ->columnSpan(1),

                    TextInput::make('panel:telegram:webhook_secret')
                        ->label('Webhook Secret')
                        ->helperText('Optional secret token to verify webhook requests. Auto-generated if empty.')
                        ->visible(fn ($get) => $get('panel:telegram:enabled'))
                        ->columnSpan(2),
                ]),
        ];
    }

    private function chatbotSettings(): array
    {
        $enabled = fn ($get) => (bool) $get('panel:chatbot:enabled');

        $toolGroupDescriptions = [];
        foreach (ChatbotToolGroup::cases() as $group) {
            $toolGroupDescriptions[$group->value] = $group->description();
        }

        return [
            Section::make('AI Assistant')
                ->description('Connect an OpenAI-compatible provider to give users an assistant that can act on their servers.')
                ->columns(2)
                ->schema([
                    Toggle::make('panel:chatbot:enabled')
                        ->label('Enable AI Assistant')
                        ->helperText('Users get a chat page on each of their servers. The assistant can act on their behalf, and only ever within their own permissions on that server.')
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->live()
                        ->columnSpan(2),

                    TextInput::make('panel:chatbot:base_url')
                        ->label('API Base URL')
                        ->helperText('Root of any OpenAI-compatible API, including the version segment — e.g. https://api.openai.com/v1 or https://your-provider.example/v1')
                        ->url()
                        ->maxLength(191)
                        ->required($enabled)
                        ->visible($enabled)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:api_key')
                        ->label('API Key')
                        ->helperText('Stored encrypted in the panel database and never exposed to clients.')
                        ->password()
                        ->revealable()
                        ->required($enabled)
                        ->visible($enabled)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:model')
                        ->label('Model')
                        ->helperText('The model identifier as the provider names it, e.g. gpt-4o-mini. It must support tool/function calling.')
                        ->datalist([
                            'gpt-4o-mini',
                            'gpt-4o',
                            'gpt-4.1-mini',
                        ])
                        ->maxLength(191)
                        ->required($enabled)
                        ->visible($enabled)
                        ->columnSpan(1),

                    Actions::make([
                        Action::make('test-chatbot-connection')
                            ->label('Test connection')
                            ->icon('tabler-plug-connected')
                            ->action('testChatbotConnection')
                            ->color('success'),
                    ])
                        ->fullWidth()
                        ->visible($enabled)
                        ->columnSpan(1),
                ]),

            Section::make('Behaviour')
                ->columns(4)
                ->collapsible()
                ->visible($enabled)
                ->schema([
                    TextInput::make('panel:chatbot:temperature')
                        ->label('Temperature')
                        ->helperText('Lower values make the assistant more deterministic; higher values more creative.')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(2)
                        ->step(0.1)
                        ->default(0.2)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:max_tokens')
                        ->label('Max Tokens')
                        ->helperText('Maximum length of a single assistant reply.')
                        ->numeric()
                        ->minValue(64)
                        ->maxValue(32000)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:max_iterations')
                        ->label('Max Tool Iterations')
                        ->helperText('How many tool calls the assistant may chain before it must answer. Higher values cost more per message.')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(25)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:history_limit')
                        ->label('History Limit')
                        ->helperText('How many earlier messages are replayed to the provider as context.')
                        ->numeric()
                        ->minValue(2)
                        ->maxValue(200)
                        ->columnSpan(1),

                    TextInput::make('panel:chatbot:timeout')
                        ->label('Request Timeout')
                        ->helperText('Seconds to wait for the provider before giving up.')
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(600)
                        ->suffix('s')
                        ->columnSpan(1),

                    Textarea::make('panel:chatbot:system_prompt')
                        ->label('Additional Instructions')
                        ->helperText('Optional. Appended to the built-in system prompt — use it for house rules, e.g. which files users may not edit.')
                        ->rows(5)
                        ->columnSpanFull(),
                ]),

            Section::make('Capabilities')
                ->visible($enabled)
                ->columnSpanFull()
                ->schema([
                    CheckboxList::make('panel:chatbot:tool_groups')
                        ->label('Enabled tool groups')
                        ->helperText('A user can still only use a tool if their own subuser permissions allow it, so these switches only ever reduce what is possible — they never grant access.')
                        ->options(ChatbotToolGroup::options())
                        ->descriptions($toolGroupDescriptions)
                        ->columns(2)
                        ->bulkToggleable()
                        ->columnSpanFull(),

                    Toggle::make('panel:chatbot:require_confirmation')
                        ->label('Require confirmation for destructive actions')
                        ->helperText('When on, the user must approve actions like power changes, file writes, deletions and subuser changes before they run. Strongly recommended.')
                        ->inline(false)
                        ->onIcon('tabler-check')
                        ->offIcon('tabler-x')
                        ->onColor('success')
                        ->offColor('danger')
                        ->columnSpanFull(),
                ]),
        ];
    }

    private function advancedSettings(): array
    {
        return [
            Section::make(trans('admin/settings.advanced.http-label'))
                ->columns(4)
                ->schema([
                    TextInput::make('panel:guzzle:timeout')
                        ->label(trans('admin/settings.advanced.request-label'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(60)
                        ->required()
                        ->columnSpan(2),

                    TextInput::make('panel:guzzle:connect_timeout')
                        ->label(trans('admin/settings.advanced.timeout-label'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(60)
                        ->required()
                        ->columnSpan(2),
                ]),

            Section::make('Cloudflare') // Proper noun, left untranslated.
                ->description('API token used for Cloudflare DNS (needs Zone.DNS edit). Domains are managed under Domains.')
                ->columns(2)
                ->schema([
                    TextInput::make('panel:cloudflare:api_token')
                        ->label('API Token')
                        ->password()
                        ->revealable()
                        ->maxLength(191)
                        ->columnSpan(1),

                    Select::make('panel:cloudflare:egg_ids')
                        ->label('Enabled Eggs')
                        ->helperText('Servers on these eggs can create subdomains.')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id'))
                        ->columnSpan(1)
                        ->native(false),
                ]),

            Section::make('Plugins')
                ->description('Client plugin installer (Modrinth/Hangar/SpigotMC). Only servers on selected eggs see the Plugins page.')
                ->columns(2)
                ->schema([
                    Select::make('panel:plugins:egg_ids')
                        ->label('Enabled Eggs')
                        ->helperText('Servers on these eggs can search and install plugins. Leave empty to disable for all.')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id'))
                        ->columnSpan(1)
                        ->native(false),
                ]),

            Section::make('Mods')
                ->description('Client mod installer (Modrinth, CurseForge). Only servers on selected eggs see the Mods page.')
                ->columns(2)
                ->schema([
                    Select::make('panel:mods:egg_ids')
                        ->label('Enabled Eggs')
                        ->helperText('Servers on these eggs can search and install mods. Leave empty to disable for all.')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id'))
                        ->columnSpan(1)
                        ->native(false),
                    TextInput::make('panel:mods:curseforge_api_key')
                        ->label('CurseForge API Key')
                        ->helperText('Required for CurseForge mod provider. Get key from console.curseforge.com')
                        ->columnSpan(1),
                ]),

            Section::make('Server Properties')
                ->description('Minecraft server.properties editor. Only servers on selected eggs see the Properties page.')
                ->columns(2)
                ->schema([
                    Select::make('panel:properties:egg_ids')
                        ->label('Enabled Eggs')
                        ->helperText('Servers on these eggs can edit server.properties from a form. Leave empty to disable for all.')
                        ->multiple()
                        ->searchable()
                        ->options(fn () => Egg::query()->orderBy('name')->pluck('name', 'id'))
                        ->columnSpan(1)
                        ->native(false),
                ]),

            Section::make(trans('admin/settings.advanced.creation-title'))
                ->columns(4)
                ->schema([
                    Toggle::make('panel:client_features:allocations:enabled')
                        ->label(trans('admin/settings.advanced.creation-title'))
                        ->inline(false)
                        ->live()
                        ->columnSpan(2),

                    TextInput::make('panel:client_features:allocations:range_start')
                        ->label(trans('admin/settings.advanced.starting-label'))
                        ->numeric()
                        ->minValue(1024)
                        ->maxValue(65535)
                        ->required(fn ($get) => $get('panel:client_features:allocations:enabled'))
                        ->visible(fn ($get) => $get('panel:client_features:allocations:enabled'))
                        ->columnSpan(1),

                    TextInput::make('panel:client_features:allocations:range_end')
                        ->label(trans('admin/settings.advanced.ending-label'))
                        ->numeric()
                        ->minValue(1024)
                        ->maxValue(65535)
                        ->gt('panel:client_features:allocations:range_start')
                        ->required(fn ($get) => $get('panel:client_features:allocations:enabled'))
                        ->visible(fn ($get) => $get('panel:client_features:allocations:enabled'))
                        ->columnSpan(1),
                ]),
        ];
    }

    protected function getFormStatePath(): ?string
    {
        return 'data';
    }

    public function save(): void
    {
        $settings = app(SettingsRepositoryInterface::class);
        $kernel = app(Kernel::class);
        $encrypter = app(Encrypter::class);
        $form = $this->getForm('form');
        $data = $form?->getState() ?? [];

        // Validate Telegram settings before saving
        if (! empty($data['panel:telegram:enabled']) && $data['panel:telegram:enabled']) {
            $token = $data['panel:telegram:bot_token'] ?? null;
            $username = $data['panel:telegram:bot_username'] ?? null;

            if (empty($token) || empty($username)) {
                Notification::make()
                    ->title('Bot token and username are required when Telegram is enabled')
                    ->danger()
                    ->send();

                return;
            }

            // Test bot token
            $testService = new TelegramBotService($settings, $encrypter);
            $apiUrl = "https://api.telegram.org/bot{$token}";
            try {
                $response = Http::get("{$apiUrl}/getMe");
                if (! $response->successful()) {
                    Notification::make()
                        ->title('Invalid bot token')
                        ->body('Could not verify bot with Telegram API')
                        ->danger()
                        ->send();

                    return;
                }

                $botInfo = $response->json('result');
                if (! empty($botInfo['username']) && strtolower($botInfo['username']) !== strtolower($username)) {
                    Notification::make()
                        ->title('Bot username mismatch')
                        ->body("Expected @{$username}, but API returned @{$botInfo['username']}")
                        ->warning()
                        ->send();
                }
            } catch (\Exception $e) {
                Notification::make()
                    ->title('Failed to verify bot token')
                    ->body($e->getMessage())
                    ->danger()
                    ->send();

                return;
            }
        }

        // Validate chatbot settings before saving
        if (! empty($data['panel:chatbot:enabled']) && $data['panel:chatbot:enabled']) {
            $baseUrl = trim((string) ($data['panel:chatbot:base_url'] ?? ''));
            $apiKey = trim((string) ($data['panel:chatbot:api_key'] ?? ''));
            $model = trim((string) ($data['panel:chatbot:model'] ?? ''));

            if ($baseUrl === '' || $apiKey === '' || $model === '') {
                Notification::make()
                    ->title('API base URL, API key and model are required when the AI assistant is enabled')
                    ->danger()
                    ->send();

                return;
            }
        }

        // Generate webhook secret if not set and Telegram is enabled
        if (! empty($data['panel:telegram:enabled']) && $data['panel:telegram:enabled'] && empty($data['panel:telegram:webhook_secret'])) {
            $data['panel:telegram:webhook_secret'] = Str::random(32);
        }

        foreach ($data as $key => $value) {
            if (in_array($key, ['mail:mailers:smtp:password', 'panel:cloudflare:api_token', 'panel:telegram:bot_token', 'panel:chatbot:api_key'], true) && ! empty($value)) {
                $value = $encrypter->encrypt($value);
            }
            if (in_array($key, ['panel:cloudflare:egg_ids', 'panel:plugins:egg_ids', 'panel:mods:egg_ids', 'panel:properties:egg_ids'], true)) {
                $value = json_encode(array_map('intval', array_filter((array) $value)));
            }
            if ($key === 'panel:chatbot:tool_groups') {
                $valid = array_column(ChatbotToolGroup::cases(), 'value');
                $value = json_encode(array_values(array_intersect(array_map('strval', array_filter((array) $value)), $valid)));
            }
            $settings->set(
                'settings::'.$key,
                is_bool($value) ? ($value ? 'true' : 'false') : $value
            );
        }

        try {
            $kernel->call('queue:restart');
        } catch (\Throwable) {
        }

        Notification::make()
            ->title(trans('admin/settings.overview.saved'))
            ->success()
            ->send();

        $this->dispatch('$refresh');
    }

    public function testMail(): void
    {
        $form = $this->getForm('form');
        $data = $form?->getState() ?? [];

        config()->set('mail.mailers.smtp.host', $data['mail:mailers:smtp:host']);
        config()->set('mail.mailers.smtp.port', $data['mail:mailers:smtp:port']);
        config()->set('mail.mailers.smtp.encryption', $data['mail:mailers:smtp:encryption']);
        config()->set('mail.mailers.smtp.username', $data['mail:mailers:smtp:username']);
        config()->set('mail.mailers.smtp.password', $data['mail:mailers:smtp:password']);

        config()->set('mail.from.address', $data['mail:from:address']);
        config()->set('mail.from.name', $data['mail:from:name']);

        try {
            app(MailManager::class)->forgetMailers();
        } catch (\Throwable $e) {
        }

        try {
            \Illuminate\Support\Facades\Notification::route('mail', auth()->user()->email)
                ->notify(new MailTested(auth()->user()));

            Notification::make()
                ->title(trans('admin/settings.mail.test-sent'))
                ->success()
                ->send();
        } catch (\Exception $exception) {
            Notification::make()
                ->title(trans('admin/settings.mail.test-failed'))
                ->body($exception->getMessage())
                ->danger()
                ->send();
        }
    }

    public function testChatbotConnection(): void
    {
        $form = $this->getForm('form');
        $data = $form?->getState() ?? [];

        $baseUrl = trim((string) ($data['panel:chatbot:base_url'] ?? ''));
        $apiKey = trim((string) ($data['panel:chatbot:api_key'] ?? ''));

        if ($baseUrl === '' || $apiKey === '') {
            Notification::make()
                ->title('API base URL and API key are required')
                ->body('Fill in both fields before testing the connection.')
                ->danger()
                ->send();

            return;
        }

        $result = OpenAiClient::verify($baseUrl, $apiKey, 15);

        if (! $result['ok']) {
            Notification::make()
                ->title('Could not reach the AI provider')
                ->body($result['message'])
                ->danger()
                ->send();

            return;
        }

        $models = $result['models'];
        $count = count($models);

        $body = $count === 1
            ? 'The provider advertises 1 model.'
            : "The provider advertises {$count} models.";

        $model = trim((string) ($data['panel:chatbot:model'] ?? ''));

        if ($model !== '' && $count > 0 && ! in_array($model, $models, true)) {
            $body .= " \"{$model}\" is not among them. Some providers do not list every model they serve, so this is not necessarily a problem.";
        }

        Notification::make()
            ->title('Connected successfully')
            ->body($body)
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label(trans('admin/settings.overview.save-btn'))
                ->icon('tabler-device-floppy')
                ->action('save')
                ->keyBindings(['mod+s']),
        ];
    }
}
