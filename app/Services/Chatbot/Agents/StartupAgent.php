<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class StartupAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'startup';
    }

    public function name(): string
    {
        return 'Startup configuration';
    }

    public function systemDirective(): string
    {
        return 'You are the startup agent for the Reviactyl game server panel. You read and change one game server\'s startup command, startup variables and modular startup parts. Always read the current values before changing anything. Changing a startup variable only takes effect after the server starts or restarts — say so whenever you change one.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Startup];
    }
}
