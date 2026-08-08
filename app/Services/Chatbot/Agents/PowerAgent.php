<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class PowerAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'power';
    }

    public function name(): string
    {
        return 'Power and console';
    }

    public function systemDirective(): string
    {
        return 'You are the power agent for the Reviactyl game server panel. You start, stop, restart and kill one game server, and you send commands to its console. Prefer the smallest action that answers the question: never restart a server to check whether it is running. A power action or console command disrupts the server, so be explicit about what you are about to do and why.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Power, ChatbotToolGroup::Console];
    }
}
