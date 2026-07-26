<?php

namespace App\Services\Chatbot\Tools\Plugins;

use App\Services\Chatbot\ToolContext;

class SearchPluginsTool extends PluginTool
{
    public function name(): string
    {
        return 'search_plugins';
    }

    public function description(): string
    {
        return 'Search a plugin registry for plugins compatible with this server\'s loader and game version. Results include the project id needed to install one, and the version already installed if there is one. Searching installs nothing on its own. To see which builds of a particular result are available, call list_plugin_versions with its project id — search results say nothing about what versions exist.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What to search for, e.g. "permissions" or "worldedit".',
                ],
                'provider' => $this->providerParameter(),
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many results to return. Defaults to 10.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'query' => 'required|string|max:200',
            'provider' => 'sometimes|nullable|string|max:64',
            'limit' => 'sometimes|nullable|integer|min:1|max:25',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $this->assertEnabled($context);

        $server = $context->server;
        $provider = $this->providerName($arguments);

        $result = $this->manager->provider($provider)->search(
            $arguments['query'],
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
            (int) ($arguments['limit'] ?? 10),
            0,
            'relevance',
        );

        // Same enrichment the plugin page does, so the model can tell the user
        // what they already have rather than proposing a redundant install.
        $installed = $server->plugins->mapWithKeys(
            fn ($plugin) => [$plugin->provider.':'.$plugin->project_id => $plugin->version_number]
        );

        return [
            'provider' => $provider,
            'total' => $result['total'] ?? 0,
            'entries' => array_map(fn (array $hit) => [
                'project_id' => $hit['id'] ?? null,
                // Passed back to install_plugin so the installed plugin is
                // listed under a readable slug rather than its project id.
                'slug' => $hit['slug'] ?? null,
                'title' => $hit['title'] ?? null,
                'description' => $hit['description'] ?? null,
                'downloads' => $hit['downloads'] ?? null,
                'installed_version' => $installed[$provider.':'.($hit['id'] ?? '')] ?? null,
            ], $result['hits'] ?? []),
        ];
    }
}
