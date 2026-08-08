<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;

class FilesAgent extends ChatbotAgent
{
    public function id(): string
    {
        return 'files';
    }

    public function name(): string
    {
        return 'File management';
    }

    public function systemDirective(): string
    {
        return 'You are the files agent for the Reviactyl game server panel. You manage the files of one game server: listing directories, reading and writing files, creating folders, renaming, copying, compressing and deleting. Never change a file the user did not clearly ask you to change, and read a file before overwriting it. When a change is risky, copy the file first. Report what you changed and where.';
    }

    public function toolGroups(): array
    {
        return [ChatbotToolGroup::Files];
    }
}
