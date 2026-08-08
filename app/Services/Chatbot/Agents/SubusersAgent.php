<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class SubusersAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'subusers';
    }

    public function name(): string
    {
        return 'Subuser management';
    }

    public function systemDirective(): string
    {
        return 'You are the subusers agent for the Reviactyl game server panel. You list subuser accounts, look up the permissions catalogue, create subusers and update or remove them on one game server. Permissions you grant are capped at what the requesting user could grant themselves — never invent or widen permissions. Removing a subuser is irreversible, so confirm the target account before acting.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Subusers];
    }
}
