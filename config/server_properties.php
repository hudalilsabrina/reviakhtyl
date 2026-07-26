<?php

/*
|--------------------------------------------------------------------------
| Minecraft server.properties schema
|--------------------------------------------------------------------------
|
| Drives the client "Properties" page. Keys not listed here are still shown
| and preserved, they just land in the "other" group as free-text fields.
|
| Labels and descriptions are NOT stored here, they live in
| resources/lang/<locale>/server/properties.php keyed by property name so
| they can be translated.
|
| type:    bool | int | string | enum
| locked:  panel-managed value, rendered read-only and never written back.
|          The egg configuration parser rewrites these from the allocation
|          on every boot, so editing them here would be pointless.
|
*/

return [

    // Render order for the property groups.
    'groups' => [
        'general',
        'gameplay',
        'world',
        'players',
        'performance',
        'network',
        'security',
        'other',
    ],

    'properties' => [
        // General
        'motd' => ['group' => 'general', 'type' => 'string', 'default' => 'A Minecraft Server'],
        'broadcast-console-to-ops' => ['group' => 'general', 'type' => 'bool', 'default' => true],
        'broadcast-rcon-to-ops' => ['group' => 'general', 'type' => 'bool', 'default' => true],
        'bug-report-link' => ['group' => 'general', 'type' => 'string', 'default' => ''],
        'resource-pack' => ['group' => 'general', 'type' => 'string', 'default' => ''],
        'resource-pack-id' => ['group' => 'general', 'type' => 'string', 'default' => ''],
        'resource-pack-prompt' => ['group' => 'general', 'type' => 'string', 'default' => ''],
        'resource-pack-sha1' => ['group' => 'general', 'type' => 'string', 'default' => ''],
        'require-resource-pack' => ['group' => 'general', 'type' => 'bool', 'default' => false],

        // Gameplay
        'gamemode' => [
            'group' => 'gameplay',
            'type' => 'enum',
            'options' => ['survival', 'creative', 'adventure', 'spectator'],
            'default' => 'survival',
        ],
        'force-gamemode' => ['group' => 'gameplay', 'type' => 'bool', 'default' => false],
        'difficulty' => [
            'group' => 'gameplay',
            'type' => 'enum',
            'options' => ['peaceful', 'easy', 'normal', 'hard'],
            'default' => 'easy',
        ],
        'hardcore' => ['group' => 'gameplay', 'type' => 'bool', 'default' => false],
        'pvp' => ['group' => 'gameplay', 'type' => 'bool', 'default' => true],
        'allow-flight' => ['group' => 'gameplay', 'type' => 'bool', 'default' => false],
        'enable-command-block' => ['group' => 'gameplay', 'type' => 'bool', 'default' => false],
        'spawn-monsters' => ['group' => 'gameplay', 'type' => 'bool', 'default' => true],
        'spawn-animals' => ['group' => 'gameplay', 'type' => 'bool', 'default' => true],
        'spawn-npcs' => ['group' => 'gameplay', 'type' => 'bool', 'default' => true],

        // World
        'level-name' => ['group' => 'world', 'type' => 'string', 'default' => 'world', 'warn' => true],
        'level-seed' => ['group' => 'world', 'type' => 'string', 'default' => '', 'warn' => true],
        'level-type' => ['group' => 'world', 'type' => 'string', 'default' => 'minecraft:normal', 'warn' => true],
        'generate-structures' => ['group' => 'world', 'type' => 'bool', 'default' => true],
        'generator-settings' => ['group' => 'world', 'type' => 'string', 'default' => '{}'],
        'allow-nether' => ['group' => 'world', 'type' => 'bool', 'default' => true],
        'max-world-size' => ['group' => 'world', 'type' => 'int', 'default' => 29999984, 'min' => 1, 'max' => 29999984],
        'initial-enabled-packs' => ['group' => 'world', 'type' => 'string', 'default' => 'vanilla'],
        'initial-disabled-packs' => ['group' => 'world', 'type' => 'string', 'default' => ''],

        // Players
        'max-players' => ['group' => 'players', 'type' => 'int', 'default' => 20, 'min' => 0, 'max' => 2147483647],
        'white-list' => ['group' => 'players', 'type' => 'bool', 'default' => false],
        'enforce-whitelist' => ['group' => 'players', 'type' => 'bool', 'default' => false],
        'player-idle-timeout' => ['group' => 'players', 'type' => 'int', 'default' => 0, 'min' => 0],
        'hide-online-players' => ['group' => 'players', 'type' => 'bool', 'default' => false],

        // Performance
        'view-distance' => ['group' => 'performance', 'type' => 'int', 'default' => 10, 'min' => 2, 'max' => 32],
        'simulation-distance' => ['group' => 'performance', 'type' => 'int', 'default' => 10, 'min' => 3, 'max' => 32],
        'entity-broadcast-range-percentage' => ['group' => 'performance', 'type' => 'int', 'default' => 100, 'min' => 10, 'max' => 1000],
        'max-tick-time' => ['group' => 'performance', 'type' => 'int', 'default' => 60000, 'min' => -1],
        'max-chained-neighbor-updates' => ['group' => 'performance', 'type' => 'int', 'default' => 1000000],
        'pause-when-empty-seconds' => ['group' => 'performance', 'type' => 'int', 'default' => 60, 'min' => -1],
        'network-compression-threshold' => ['group' => 'performance', 'type' => 'int', 'default' => 256, 'min' => -1],
        'sync-chunk-writes' => ['group' => 'performance', 'type' => 'bool', 'default' => true],
        'use-native-transport' => ['group' => 'performance', 'type' => 'bool', 'default' => true],
        'enable-jmx-monitoring' => ['group' => 'performance', 'type' => 'bool', 'default' => false],
        'region-file-compression' => [
            'group' => 'performance',
            'type' => 'enum',
            'options' => ['deflate', 'lz4', 'none'],
            'default' => 'deflate',
        ],

        // Network
        'server-ip' => ['group' => 'network', 'type' => 'string', 'default' => '', 'locked' => true],
        'server-port' => ['group' => 'network', 'type' => 'int', 'default' => 25565, 'locked' => true],
        'query.port' => ['group' => 'network', 'type' => 'int', 'default' => 25565, 'locked' => true],
        'rcon.port' => ['group' => 'network', 'type' => 'int', 'default' => 25575, 'locked' => true],
        'enable-query' => ['group' => 'network', 'type' => 'bool', 'default' => false],
        'enable-rcon' => ['group' => 'network', 'type' => 'bool', 'default' => false],
        'rcon.password' => ['group' => 'network', 'type' => 'string', 'default' => '', 'sensitive' => true],
        'enable-status' => ['group' => 'network', 'type' => 'bool', 'default' => true],
        'accepts-transfers' => ['group' => 'network', 'type' => 'bool', 'default' => false],
        'rate-limit' => ['group' => 'network', 'type' => 'int', 'default' => 0, 'min' => 0],

        // Security
        'online-mode' => ['group' => 'security', 'type' => 'bool', 'default' => true, 'warn' => true],
        'enforce-secure-profile' => ['group' => 'security', 'type' => 'bool', 'default' => true],
        'prevent-proxy-connections' => ['group' => 'security', 'type' => 'bool', 'default' => false],
        'spawn-protection' => ['group' => 'security', 'type' => 'int', 'default' => 16, 'min' => 0],
        'op-permission-level' => ['group' => 'security', 'type' => 'int', 'default' => 4, 'min' => 1, 'max' => 4],
        'function-permission-level' => ['group' => 'security', 'type' => 'int', 'default' => 2, 'min' => 1, 'max' => 4],
        'log-ips' => ['group' => 'security', 'type' => 'bool', 'default' => true],
        'text-filtering-config' => ['group' => 'security', 'type' => 'string', 'default' => ''],
        'text-filtering-version' => ['group' => 'security', 'type' => 'int', 'default' => 0, 'min' => 0],
    ],

];
