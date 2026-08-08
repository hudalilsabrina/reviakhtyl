<?php

namespace App\Enum;

/**
 * Groups of chatbot tools that an administrator can independently enable or
 * disable. A tool is only ever exposed to the model when its group is enabled
 * panel-wide *and* the requesting user holds the tool's own permissions.
 */
enum ChatbotToolGroup: string
{
    case Server = 'server';
    case Power = 'power';
    case Console = 'console';
    case Files = 'files';
    case Subusers = 'subusers';
    case Startup = 'startup';
    case Plugins = 'plugins';
    case Mods = 'mods';
    case Web = 'web';

    public function label(): string
    {
        return match ($this) {
            self::Server => 'Server information',
            self::Power => 'Power control',
            self::Console => 'Console commands',
            self::Files => 'File management',
            self::Subusers => 'Subuser management',
            self::Startup => 'Startup & variables',
            self::Plugins => 'Plugin management',
            self::Mods => 'Mod management',
            self::Web => 'Web access',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Server => 'Read the server state, resource usage, usage history, activity log and allocations.',
            self::Power => 'Start, stop, restart and kill the server.',
            self::Console => 'Send commands to the server console.',
            self::Files => 'Browse, read, write, move, archive and delete files.',
            self::Subusers => 'List, invite, update and remove subusers.',
            self::Startup => 'Read and change startup variables and modular startup parts.',
            self::Plugins => 'Search, install, update and remove plugins from the configured registries.',
            self::Mods => 'Search, install, update and remove mods from the configured registries.',
            self::Web => 'Fetch and read public web pages. Off by default: web content is untrusted, and fetching can leak the panel\'s network if misused.',
        };
    }

    /**
     * The groups enabled on a fresh installation.
     *
     * Console access, subuser management and the two registry groups are
     * deliberately left off. Console and subuser tools are the easiest to abuse
     * through prompt injection from file or console content the model reads,
     * and installing a plugin or mod means fetching third-party code from the
     * internet and running it on the game server — the highest-consequence
     * action available, so an administrator opts into it explicitly.
     */
    public static function defaults(): array
    {
        return [
            self::Server->value,
            self::Power->value,
            self::Files->value,
            self::Startup->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
