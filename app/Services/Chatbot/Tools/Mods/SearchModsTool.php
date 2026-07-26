<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Models\ServerMod;
use App\Services\Chatbot\ToolContext;

class SearchModsTool extends ModTool
{
    public function name(): string
    {
        return 'search_mods';
    }

    public function description(): string
    {
        return 'Search a mod registry for mods this server can actually run. The search is filtered automatically to the server\'s mod loader and game version, so results that come back are compatible; a mod the user names but that does not appear here has no build for this server. Each result carries the project_id that install_mod needs, and an installed_version telling you which version of it is already on the server, if any. This only searches — it installs nothing.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'What to search for, e.g. "sodium" or "world edit".',
                ],
                'provider' => $this->providerParameter(),
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many results to return, 1 to 25. Defaults to 10.',
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
            'provider' => 'sometimes|nullable|string|max:32',
            'limit' => 'sometimes|integer|min:1|max:25',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $provider = $this->providerName($arguments);
        $limit = (int) ($arguments['limit'] ?? 10);

        $result = $this->manager->provider($provider)->search(
            $arguments['query'],
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
            $limit,
            0,
            'relevance',
        );

        // Same enrichment the panel's own search does, so the model can say
        // "you already have 0.5.11" instead of offering to install it again.
        $installed = $server->mods
            ->mapWithKeys(fn (ServerMod $mod) => [$mod->provider.':'.$mod->project_id => $mod->version_number]);

        $hits = array_map(fn (array $hit) => $hit + [
            'installed_version' => $installed[$provider.':'.$hit['id']] ?? null,
        ], $result['hits'] ?? []);

        return [
            'provider' => $provider,
            'game_version' => $this->manager->gameVersion($server),
            'loaders' => $this->manager->loaders($server),
            'total' => $result['total'] ?? count($hits),
            // Named "entries" so an oversized result set is trimmed by the
            // executor rather than discarded wholesale.
            'entries' => $hits,
        ];
    }
}
