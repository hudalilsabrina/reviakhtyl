<?php

namespace App\Services\Plugins;

interface PluginProviderInterface
{
    /**
     * Search projects. Returns ['hits' => array, 'total' => int].
     * Each hit: id, slug, title, description, author, icon_url, downloads.
     */
    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset): array;

    /**
     * Latest version compatible with the given loaders/game version.
     * Returns: id, version_number, file_name, download_url, game_versions, loaders.
     */
    public function resolveVersion(string $projectId, array $loaders, ?string $gameVersion): ?array;

    /**
     * Newest version newer than $currentVersionId, or null if up to date.
     */
    public function latestVersion(string $projectId, string $currentVersionId, array $loaders, ?string $gameVersion): ?array;

    /**
     * List versions compatible with the given loaders/game version, newest first.
     * Same shape as resolveVersion() items.
     */
    public function versions(string $projectId, array $loaders, ?string $gameVersion, int $limit = 25): array;
}
