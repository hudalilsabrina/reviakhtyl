<?php

namespace App\Services\Mods;

interface ModProviderInterface
{
    /**
     * Search projects. Returns ['hits' => array, 'total' => int].
     * Each hit: id, slug, title, description, author, icon_url, downloads.
     * $sort is one of: relevance, downloads, updated.
     */
    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance'): array;

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
     * Same shape as resolveVersion() items, plus a 'dependencies' key:
     * [['project_id' => string, 'required' => bool], ...] (may be empty).
     */
    public function versions(string $projectId, array $loaders, ?string $gameVersion, int $limit = 25): array;

    /**
     * Search modpacks (no loader filtering — loader is irrelevant for packs).
     * Same return shape as search().
     */
    public function searchModpacks(string $query, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance'): array;

    /**
     * Project display data for the given ids, keyed by id: ['title' => ..., 'icon_url' => ...].
     * Unknown ids are omitted.
     */
    public function projects(array $ids): array;
}
