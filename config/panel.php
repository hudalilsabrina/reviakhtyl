<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Restricted Environment
    |--------------------------------------------------------------------------
    |
    | Set this environment variable to true to enable a restricted configuration
    | setup on the panel. When set to true, configurations stored in the
    | database will not be applied.
    */

    'load_environment_only' => (bool) env('APP_ENVIRONMENT_ONLY', false),

    /*
    |--------------------------------------------------------------------------
    | Service Author
    |--------------------------------------------------------------------------
    |
    | Each panel installation is assigned a unique UUID to identify the
    | author of custom services, and make upgrades easier by identifying
    | standard Reviactyl shipped services.
    */

    'service' => [
        'author' => env('APP_SERVICE_AUTHOR', 'unknown@unknown.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | Should login success and failure events trigger an email to the user?
    */

    'auth' => [
        '2fa_required' => env('APP_2FA_REQUIRED', 0),
        'registration_enabled' => env('PANEL_REGISTRATION_ENABLED', true),
        '2fa' => [
            'bytes' => 32,
            'window' => env('APP_2FA_WINDOW', 4),
            'verify_newer' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Certain pagination result counts can be configured here and will take
    | effect globally.
    */

    'paginate' => [
        'frontend' => [
            'servers' => env('APP_PAGINATE_FRONT_SERVERS', 15),
        ],
        'admin' => [
            'servers' => env('APP_PAGINATE_ADMIN_SERVERS', 25),
            'users' => env('APP_PAGINATE_ADMIN_USERS', 25),
        ],
        'api' => [
            'nodes' => env('APP_PAGINATE_API_NODES', 25),
            'servers' => env('APP_PAGINATE_API_SERVERS', 25),
            'users' => env('APP_PAGINATE_API_USERS', 25),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Guzzle Connections
    |--------------------------------------------------------------------------
    |
    | Configure the timeout to be used for Guzzle connections here.
    */

    'guzzle' => [
        'timeout' => env('GUZZLE_TIMEOUT', 15),
        'connect_timeout' => env('GUZZLE_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | File Scan
    |--------------------------------------------------------------------------
    |
    | Optional anti-virus scanning for files (JARs, etc.) using clamscan.
    */
    'file_scan' => [
        'enabled' => env('PANEL_FILE_SCAN_ENABLED', false),
        'binary' => env('PANEL_FILE_SCAN_BINARY', 'clamscan'),
        'max_scan_size' => 256 * 1024 * 1024,
        'strict' => env('PANEL_FILE_SCAN_STRICT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | CDN
    |--------------------------------------------------------------------------
    |
    | Information for the panel to use when contacting the CDN to confirm
    | if panel is up to date.
    */

    'cdn' => [
        'cache_time' => 60,
        'url' => 'https://reviactyl.app/api/v26/get-version',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client Features
    |--------------------------------------------------------------------------
    |
    | Allow clients to create their own databases.
    */

    'client_features' => [
        'databases' => [
            'enabled' => env('PANEL_CLIENT_DATABASES_ENABLED', true),
            'allow_random' => env('PANEL_CLIENT_DATABASES_ALLOW_RANDOM', true),
        ],

        'schedules' => [
            // The total number of tasks that can exist for any given schedule at once.
            'per_schedule_task_limit' => env('PANEL_PER_SCHEDULE_TASK_LIMIT', 10),
        ],

        'allocations' => [
            'enabled' => env('PANEL_CLIENT_ALLOCATIONS_ENABLED', false),
            'range_start' => env('PANEL_CLIENT_ALLOCATIONS_RANGE_START'),
            'range_end' => env('PANEL_CLIENT_ALLOCATIONS_RANGE_END'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Editor
    |--------------------------------------------------------------------------
    |
    | This array includes the MIME filetypes that can be edited via the web.
    */

    'files' => [
        'max_edit_size' => env('PANEL_FILES_MAX_EDIT_SIZE', 1024 * 1024 * 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dynamic Environment Variables
    |--------------------------------------------------------------------------
    |
    | Place dynamic environment variables here that should be auto-appended
    | to server environment fields when the server is created or updated.
    |
    | Items should be in 'key' => 'value' format, where key is the environment
    | variable name, and value is the server-object key. For example:
    |
    | 'P_SERVER_CREATED_AT' => 'created_at'
    */

    'environment_variables' => [
        'P_SERVER_ALLOCATION_LIMIT' => 'allocation_limit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Asset Verification
    |--------------------------------------------------------------------------
    |
    | This section controls the output format for JS & CSS assets.
    */

    'assets' => [
        'use_hash' => env('PANEL_USE_ASSET_HASH', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Notification Settings
    |--------------------------------------------------------------------------
    |
    | This section controls what notifications are sent to users.
    */

    'email' => [
        // Should an email be sent to a server owner once their server has completed it's first install process?
        'send_install_notification' => env('PANEL_SEND_INSTALL_NOTIFICATION', true),
        // Should an email be sent to a server owner whenever their server is reinstalled?
        'send_reinstall_notification' => env('PANEL_SEND_REINSTALL_NOTIFICATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry Settings
    |--------------------------------------------------------------------------
    |
    | This section controls the telemetry sent by Reviactyl.
    */

    'telemetry' => [
        'enabled' => env('PANEL_TELEMETRY_ENABLED', true),
    ],

    'features' => [
        'new_server_identifiers' => (bool) env('PANEL_USE_SERVER_IDENTIFIERS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Chatbot
    |--------------------------------------------------------------------------
    |
    | Defaults for the server assistant. Every value here is overridden by the
    | matching `settings::panel:chatbot:*` key written from the admin area, so
    | these only act as a fallback for installations that prefer configuring
    | the panel entirely through the environment.
    */

    'chatbot' => [
        'enabled' => (bool) env('PANEL_CHATBOT_ENABLED', false),
        // Admin-scope chatbot for root administrators; the panel still needs
        // PANEL_CHATBOT_ENABLED and a provider configured for it to work.
        'admin_enabled' => (bool) env('PANEL_CHATBOT_ADMIN_ENABLED', true),
        'base_url' => env('PANEL_CHATBOT_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('PANEL_CHATBOT_API_KEY'),
        'model' => env('PANEL_CHATBOT_MODEL', 'gpt-4o-mini'),
        'temperature' => (float) env('PANEL_CHATBOT_TEMPERATURE', 0.2),
        'max_tokens' => (int) env('PANEL_CHATBOT_MAX_TOKENS', 1024),
        'max_iterations' => (int) env('PANEL_CHATBOT_MAX_ITERATIONS', 8),
        'history_limit' => (int) env('PANEL_CHATBOT_HISTORY_LIMIT', 30),
        'context_tokens' => (int) env('PANEL_CHATBOT_CONTEXT_TOKENS', 24000),
        'compaction' => (bool) env('PANEL_CHATBOT_COMPACTION', true),
        // Route turns through a router that delegates to narrow sub-agents
        // instead of the single flat tool list.
        'orchestration' => (bool) env('PANEL_CHATBOT_ORCHESTRATION', false),
        'timeout' => (int) env('PANEL_CHATBOT_TIMEOUT', 120),
        'require_confirmation' => (bool) env('PANEL_CHATBOT_REQUIRE_CONFIRMATION', true),
        'system_prompt' => env('PANEL_CHATBOT_SYSTEM_PROMPT'),
        // Comma-separated list, e.g. "server,power,files". Left null the panel
        // falls back to App\Enum\ChatbotToolGroup::defaults().
        'tool_groups' => ($groups = env('PANEL_CHATBOT_TOOL_GROUPS'))
            ? array_values(array_filter(array_map('trim', explode(',', (string) $groups))))
            : null,
    ],
];
