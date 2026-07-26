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

    public function label(): string
    {
        return match ($this) {
            self::Server => 'Server information',
            self::Power => 'Power control',
            self::Console => 'Console commands',
            self::Files => 'File management',
            self::Subusers => 'Subuser management',
            self::Startup => 'Startup & variables',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Server => 'Read the server state, resource usage and allocations.',
            self::Power => 'Start, stop, restart and kill the server.',
            self::Console => 'Send commands to the server console.',
            self::Files => 'Browse, read, write, move, archive and delete files.',
            self::Subusers => 'List, invite, update and remove subusers.',
            self::Startup => 'Read and change startup variables and modular startup parts.',
        };
    }

    /**
     * The groups enabled on a fresh installation. Console access and subuser
     * management are deliberately left off by default: they are the two groups
     * that can most easily be abused through prompt injection from file or
     * console content the model reads.
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
