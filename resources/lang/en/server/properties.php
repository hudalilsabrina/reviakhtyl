<?php

return [
    'title' => 'Properties',
    'subtitle' => 'Edit the settings in server.properties without touching the file by hand.',

    'tab_form' => 'Settings',
    'tab_raw' => 'Raw file',

    'search_label' => 'Search settings',
    'search_placeholder' => 'Search settings...',
    'no_results' => 'No settings match your search.',
    'expand_all' => 'Expand all',
    'collapse_all' => 'Collapse all',
    'group_count_one' => ':count setting',
    'group_count_other' => ':count settings',
    'group_changed_one' => ':count changed',
    'group_changed_other' => ':count changed',

    'save' => 'Save changes',
    'saving' => 'Saving...',
    'discard' => 'Discard',
    'saved' => 'Properties saved.',
    'unsaved_one' => ':count unsaved change',
    'unsaved_other' => ':count unsaved changes',
    'show_changed' => 'Show changed',
    'reset' => 'Reset to default',
    'reveal' => 'Show value',
    'hide' => 'Hide value',
    'invalid' => 'Fix the highlighted settings before saving.',

    'error_number' => 'Must be a whole number.',
    'error_min' => 'Must be at least :min.',
    'error_max' => 'Must be at most :max.',

    'missing_title' => 'No properties file yet',
    'missing_file' => 'This server has never started, so it has no server.properties. Saving will create one from the defaults below.',
    'locked' => 'Managed by the panel from this server\'s allocation. Changing it here would be overwritten on the next start.',
    'warn' => 'Changing this can affect an existing world. Take a backup first.',

    'restart_title' => 'Restart to apply',
    'restart_body' => 'Minecraft only reads server.properties at startup. Restart the server for these changes to take effect.',
    'restart_action' => 'Restart now',
    'start_action' => 'Start now',
    'restart_offline' => 'Minecraft only reads server.properties at startup, so this server will pick the changes up the next time it starts.',
    'restart_disconnected' => 'Waiting for a connection to the server...',

    'eula_title' => 'Minecraft EULA not accepted',
    'eula_body' => 'This server will not start until the Minecraft End User Licence Agreement is accepted.',
    'eula_action' => 'Accept EULA',
    'eula_link' => 'Read the EULA',
    'eula_accepted' => 'EULA accepted.',

    'raw_warning' => 'Saving here overwrites the whole of server.properties.',
    'raw_save' => 'Save file',
    'raw_blocked' => 'Save or discard your changes on the Settings tab first.',

    'groups' => [
        'general' => 'General',
        'gameplay' => 'Gameplay',
        'world' => 'World',
        'players' => 'Players',
        'performance' => 'Performance',
        'network' => 'Network',
        'security' => 'Security',
        'other' => 'Other',
    ],

    'fields' => [
        // General
        'motd' => [
            'label' => 'Server description',
            'description' => 'The message shown under the server name in the multiplayer list.',
        ],
        'broadcast-console-to-ops' => [
            'label' => 'Broadcast console commands to ops',
            'description' => 'Show commands run from the console to online operators.',
        ],
        'broadcast-rcon-to-ops' => [
            'label' => 'Broadcast RCON commands to ops',
            'description' => 'Show commands run over RCON to online operators.',
        ],
        'bug-report-link' => [
            'label' => 'Bug report link',
            'description' => 'URL players are sent to when reporting a bug.',
        ],
        'resource-pack' => [
            'label' => 'Resource pack URL',
            'description' => 'Direct download link to a resource pack players are offered on join.',
        ],
        'resource-pack-id' => [
            'label' => 'Resource pack ID',
            'description' => 'UUID identifying the resource pack.',
        ],
        'resource-pack-prompt' => [
            'label' => 'Resource pack prompt',
            'description' => 'Message shown when players are asked to download the resource pack.',
        ],
        'resource-pack-sha1' => [
            'label' => 'Resource pack SHA-1',
            'description' => 'SHA-1 hash of the resource pack, used to verify the download.',
        ],
        'require-resource-pack' => [
            'label' => 'Require resource pack',
            'description' => 'Kick players who decline the resource pack.',
        ],

        // Gameplay
        'gamemode' => [
            'label' => 'Default game mode',
            'description' => 'The mode new players start in.',
        ],
        'force-gamemode' => [
            'label' => 'Force game mode',
            'description' => 'Put players back into the default game mode every time they join.',
        ],
        'difficulty' => [
            'label' => 'Difficulty',
            'description' => 'How hard mobs hit and whether hunger drains.',
        ],
        'hardcore' => [
            'label' => 'Hardcore',
            'description' => 'Players are permanently set to spectator when they die.',
        ],
        'pvp' => [
            'label' => 'Player versus player',
            'description' => 'Allow players to damage each other.',
        ],
        'allow-flight' => [
            'label' => 'Allow flight',
            'description' => 'Permit flight mods in survival. Leave off unless a plugin needs it.',
        ],
        'enable-command-block' => [
            'label' => 'Enable command blocks',
            'description' => 'Allow command blocks to run in the world.',
        ],
        'spawn-monsters' => [
            'label' => 'Spawn monsters',
            'description' => 'Allow hostile mobs to spawn.',
        ],
        'spawn-animals' => [
            'label' => 'Spawn animals',
            'description' => 'Allow passive mobs to spawn.',
        ],
        'spawn-npcs' => [
            'label' => 'Spawn villagers',
            'description' => 'Allow villagers to spawn.',
        ],

        // World
        'level-name' => [
            'label' => 'World folder',
            'description' => 'Name of the folder the world is stored in. Pointing this at a new name generates a fresh world.',
        ],
        'level-seed' => [
            'label' => 'World seed',
            'description' => 'Seed used when generating a new world. Ignored once the world exists.',
        ],
        'level-type' => [
            'label' => 'World type',
            'description' => 'Generator used for new chunks, for example minecraft:normal or minecraft:flat.',
        ],
        'generate-structures' => [
            'label' => 'Generate structures',
            'description' => 'Generate villages, temples and other structures.',
        ],
        'generator-settings' => [
            'label' => 'Generator settings',
            'description' => 'JSON customising the world generator. Mostly used with superflat worlds.',
        ],
        'allow-nether' => [
            'label' => 'Allow the Nether',
            'description' => 'Let players travel to the Nether.',
        ],
        'max-world-size' => [
            'label' => 'Maximum world size',
            'description' => 'World border radius in blocks.',
        ],
        'initial-enabled-packs' => [
            'label' => 'Enabled data packs',
            'description' => 'Comma separated data packs enabled when the world is created.',
        ],
        'initial-disabled-packs' => [
            'label' => 'Disabled data packs',
            'description' => 'Comma separated data packs disabled when the world is created.',
        ],

        // Players
        'max-players' => [
            'label' => 'Maximum players',
            'description' => 'How many players can be online at once.',
        ],
        'white-list' => [
            'label' => 'Whitelist',
            'description' => 'Only players on the whitelist can join.',
        ],
        'enforce-whitelist' => [
            'label' => 'Enforce whitelist',
            'description' => 'Kick players already online who are not on the whitelist.',
        ],
        'player-idle-timeout' => [
            'label' => 'Idle timeout (minutes)',
            'description' => 'Kick players after this many minutes of inactivity. 0 disables it.',
        ],
        'hide-online-players' => [
            'label' => 'Hide online players',
            'description' => 'Do not list online players in the server status response.',
        ],

        // Performance
        'view-distance' => [
            'label' => 'View distance',
            'description' => 'Chunks sent to each player. The single biggest lever on memory and CPU use.',
        ],
        'simulation-distance' => [
            'label' => 'Simulation distance',
            'description' => 'Chunks around each player where mobs and blocks stay active.',
        ],
        'entity-broadcast-range-percentage' => [
            'label' => 'Entity broadcast range',
            'description' => 'Percentage of the default distance at which entities become visible.',
        ],
        'max-tick-time' => [
            'label' => 'Maximum tick time (ms)',
            'description' => 'Watchdog crashes the server if a tick takes longer than this. -1 disables it.',
        ],
        'max-chained-neighbor-updates' => [
            'label' => 'Maximum chained neighbour updates',
            'description' => 'Limit on consecutive block updates before the rest are skipped.',
        ],
        'pause-when-empty-seconds' => [
            'label' => 'Pause when empty (seconds)',
            'description' => 'Stop ticking the world after this long with nobody online.',
        ],
        'network-compression-threshold' => [
            'label' => 'Network compression threshold',
            'description' => 'Packets larger than this many bytes are compressed. -1 disables compression.',
        ],
        'sync-chunk-writes' => [
            'label' => 'Synchronous chunk writes',
            'description' => 'Write chunks to disk synchronously. Safer, but slower on busy servers.',
        ],
        'use-native-transport' => [
            'label' => 'Use native transport',
            'description' => 'Use optimised Linux packet handling.',
        ],
        'enable-jmx-monitoring' => [
            'label' => 'Enable JMX monitoring',
            'description' => 'Expose JVM metrics over JMX.',
        ],
        'region-file-compression' => [
            'label' => 'Region file compression',
            'description' => 'Algorithm used to compress region files on disk.',
        ],

        // Network
        'server-ip' => [
            'label' => 'Server IP',
            'description' => 'Address the server binds to.',
        ],
        'server-port' => [
            'label' => 'Server port',
            'description' => 'Port players connect to.',
        ],
        'query.port' => [
            'label' => 'Query port',
            'description' => 'Port used by the GameSpy query protocol.',
        ],
        'rcon.port' => [
            'label' => 'RCON port',
            'description' => 'Port used for remote console access.',
        ],
        'enable-query' => [
            'label' => 'Enable query',
            'description' => 'Allow external tools to poll the server for player counts.',
        ],
        'enable-rcon' => [
            'label' => 'Enable RCON',
            'description' => 'Allow remote console access. Set a strong password before turning this on.',
        ],
        'rcon.password' => [
            'label' => 'RCON password',
            'description' => 'Password required for remote console access.',
        ],
        'enable-status' => [
            'label' => 'Enable status',
            'description' => 'Show the server as online in the multiplayer list.',
        ],
        'accepts-transfers' => [
            'label' => 'Accept transfers',
            'description' => 'Allow players to be transferred here from another server.',
        ],
        'rate-limit' => [
            'label' => 'Packet rate limit',
            'description' => 'Packets per second before a player is kicked. 0 disables the limit.',
        ],

        // Security
        'online-mode' => [
            'label' => 'Online mode',
            'description' => 'Verify players against Mojang. Turning this off lets anyone join under any name.',
        ],
        'enforce-secure-profile' => [
            'label' => 'Enforce secure profile',
            'description' => 'Require players to have a signed chat profile.',
        ],
        'prevent-proxy-connections' => [
            'label' => 'Prevent proxy connections',
            'description' => 'Block players connecting through a VPN or proxy.',
        ],
        'spawn-protection' => [
            'label' => 'Spawn protection radius',
            'description' => 'Blocks around spawn that only operators can build in. 0 disables it.',
        ],
        'op-permission-level' => [
            'label' => 'Operator permission level',
            'description' => 'How much power an operator gets, from 1 to 4.',
        ],
        'function-permission-level' => [
            'label' => 'Function permission level',
            'description' => 'Permission level data pack functions run at.',
        ],
        'log-ips' => [
            'label' => 'Log IP addresses',
            'description' => 'Include player IP addresses in the console log.',
        ],
        'text-filtering-config' => [
            'label' => 'Text filtering config',
            'description' => 'Configuration for the chat text filtering service.',
        ],
        'text-filtering-version' => [
            'label' => 'Text filtering version',
            'description' => 'Version of the text filtering configuration.',
        ],
    ],
];
