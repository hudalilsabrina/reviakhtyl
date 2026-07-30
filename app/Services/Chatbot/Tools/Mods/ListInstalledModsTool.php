<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Models\ServerMod;
use App\Services\Chatbot\ToolContext;

class ListInstalledModsTool extends ModTool
{
    public function name(): string
    {
        return 'list_mods';
    }

    public function description(): string
    {
        return 'List the mods installed on this server through the mod installer, with the slug you need to update, remove or toggle each one. Also reports the mod loader and game version the server runs, which is what any newly installed mod has to match. A mod marked disabled is still on disk but is not loaded by the server. Jars uploaded by hand into /mods are not listed unless someone registered them with the mod installer.';
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $mods = $server->mods;

        return [
            'count' => $mods->count(),
            'game_version' => $this->manager->gameVersion($server),
            'loaders' => $this->manager->loaders($server),
            // Named "entries" so an oversized list is trimmed by the executor
            // rather than discarded wholesale.
            'entries' => $mods->map(fn (ServerMod $mod) => $this->present($mod))->values()->all(),
        ];
    }
}
