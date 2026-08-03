<?php

namespace App\Services\Datapacks;

/**
 * Contract for datapack registry providers (Modrinth, etc.).
 */
interface DatapackProviderInterface
{
    /**
     * Search projects. Returns ['hits' => array, 'total' => int].
     * Each hit: id, slug, title, description, author, icon_url, downloads.
     * $sort is one of: relevance, downloads, updated.
     */
    public function search(string $query, array $gameVersions, int $limit, int $offset, string $sort = 'relevance'): array;

    /**
     * Latest version compatible with the given game versions.
     * Returns: id, version_number, file_name, download_url, game_versions.
     */
    public function resolveVersion(string $projectId, array $gameVersions): ?array;

    /**
     * Newest version newer than $currentVersionId, or null if up to date.
     */
    public function latestVersion(string $projectId, string $currentVersionId, array $gameVersions): ?array;

    /**
     * List versions compatible with the given game versions, newest first.
     * Same shape as resolveVersion() items.
     */
    public function versions(string $projectId, array $gameVersions, int $limit = 25): array;

    /**
     * Project display data for the given ids, keyed by id: ['title' => ..., 'icon_url' => ...].
     * Unknown ids are omitted.
     */
    public function projects(array $ids): array;
}
