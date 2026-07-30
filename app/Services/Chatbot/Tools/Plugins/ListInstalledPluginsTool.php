<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Models\ServerPlugin;
use App\Services\Chatbot\ToolContext;

class ListInstalledPluginsTool extends PluginTool
{
    public function name(): string
    {
        return 'list_plugins';
    }

    public function description(): string
    {
        return 'List the plugins installed on this server, with the version of each and whether it is currently disabled. Also reports the server\'s game version and plugin loader, which decide what is compatible. Call this before updating or removing anything so you use the right name.';
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $server = $context->server;

        return [
            'game_version' => $this->manager->gameVersion($server),
            'loaders' => $this->manager->loaders($server),
            'entries' => $server->plugins
                ->map(fn (ServerPlugin $plugin) => $this->describe($plugin))
                ->values()
                ->all(),
        ];
    }
}
