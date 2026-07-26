<?php

namespace App\Services\Chatbot\Tools\Mods;

use App\Services\Chatbot\ToolContext;

class ListModVersionsTool extends ModTool
{
    public function name(): string
    {
        return 'list_mod_versions';
    }

    public function description(): string
    {
        return 'List the downloadable versions of one mod, newest first, filtered to those the registry marks as compatible with this server\'s loader and game version. Each entry has a version_id you can pass to install_mod to install that exact build. Use this whenever the newest release does not suit — for example when the server runs an older game version, or when a mod needs rolling back to the build that last worked. An empty list means the registry publishes nothing compatible with this server, not that the mod has only one version.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'string',
                    'description' => 'The registry project id, from search_mods.',
                ],
                'provider' => $this->providerParameter(),
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many versions to return, newest first. Defaults to 15.',
                ],
            ],
            'required' => ['project_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|string|max:128',
            'provider' => 'sometimes|nullable|string|max:32',
            'limit' => 'sometimes|nullable|integer|min:1|max:50',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $this->assertEnabled($server);

        $provider = $this->providerName($arguments);
        $gameVersion = $this->manager->gameVersion($server);
        $loaders = $this->manager->loaders($server);

        $versions = $this->manager->provider($provider)->versions(
            $arguments['project_id'],
            $loaders,
            $gameVersion,
            (int) ($arguments['limit'] ?? 15),
        );

        return [
            'provider' => $provider,
            'game_version' => $gameVersion,
            'loaders' => $loaders,
            // Spelled out so an empty list is not mistaken for "only one version
            // exists", and so an unfiltered list is not mistaken for a compatible one.
            'note' => $this->note($gameVersion, $versions === []),
            'entries' => array_map(fn (array $version) => [
                'version_id' => $version['id'] ?? null,
                'version_number' => $version['version_number'] ?? null,
                'file_name' => $version['file_name'] ?? null,
                'game_versions' => $version['game_versions'] ?? [],
                'loaders' => $version['loaders'] ?? [],
            ], $versions),
        ];
    }

    /**
     * The server's game version comes from the MINECRAFT_VERSION startup variable.
     * When that is "latest" the panel has no concrete version to filter on, so the
     * registry returns everything — including builds this server cannot run. Saying
     * so is the difference between the assistant recommending a working build and
     * confidently recommending an incompatible one.
     */
    private function note(?string $gameVersion, bool $empty): string
    {
        if ($gameVersion === null) {
            return 'This server\'s MINECRAFT_VERSION is not set to a concrete version, so these results are NOT filtered for compatibility'
                .' — check each entry\'s game_versions against the version the server actually runs before installing,'
                .' and suggest setting MINECRAFT_VERSION so future installs are filtered automatically.';
        }

        return $empty
            ? "The registry lists no version of this mod compatible with game version $gameVersion on this server's loader."
            : 'Pass a version_id to install_mod to install that exact build.';
    }
}
