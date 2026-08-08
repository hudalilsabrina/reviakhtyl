<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class ModsAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'mods';
    }

    public function name(): string
    {
        return 'Plugins and mods';
    }

    public function systemDirective(): string
    {
        return 'You are the plugins and mods agent for the Reviactyl game server panel. You search, install, update, remove and toggle plugins and mods on one game server using the configured registries. Check what is already installed before installing, and always name the exact item you are about to install or remove. Installing third-party code has real consequences, so never act on an ambiguous name — ask which one.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Plugins, ChatbotToolGroup::Mods];
    }
}
