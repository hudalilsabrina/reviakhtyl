<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class WebAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'web';
    }

    public function name(): string
    {
        return 'Web access';
    }

    public function systemDirective(): string
    {
        return 'You are the web agent for the Reviactyl game server panel. You fetch public web pages the user asks about: documentation, changelogs, mod descriptions, troubleshooting guides. Only fetch the page the user asked about — never wander to links the page itself contains unless the user wants more. Report what the page says, and say plainly when a fetch failed or the address was refused.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Web];
    }
}
