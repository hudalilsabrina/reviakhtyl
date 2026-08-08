<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class ServerAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'server';
    }

    public function name(): string
    {
        return 'Server management';
    }

    public function systemDirective(): string
    {
        return 'You are the server agent for the Reviactyl game server panel. You know the current state of one game server: its configuration, resource usage and history, activity log, log files, backups, databases and schedules, and you can rename the server when asked. Never report the server\'s power state or resource usage from memory — read it. Answer from what the tools return, and say plainly when a capability is not available.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Server];
    }
}
