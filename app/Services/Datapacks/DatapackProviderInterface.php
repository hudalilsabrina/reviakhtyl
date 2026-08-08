<?php

namespace App\Services\Datapacks;

/**
 * Contract for a datapack registry provider (Modrinth, CurseForge).
 *
 * Unlike mods/plugins there is no "loader" concept — datapacks are filtered
 * by Minecraft version only, so the loaders array is replaced by a list of
 * game versions.
 */
interface DatapackProviderInterface
{
    /**
     * @param  string  $query  search term
     * @param  string[]  $gameVersions  Minecraft versions to filter by
     * @return array{hits: array<int, array<string, mixed>>, total: int}
     */
    public function search(string $query, array $gameVersions, int $limit, int $offset, string $sort = 'relevance'): array;

    /**
     * @param  string[]  $gameVersions
     * @return array<string, mixed>|null
     */
    public function resolveVersion(string $projectId, array $gameVersions): ?array;

    /**
     * @param  string[]  $gameVersions
     * @return array<int, array<string, mixed>>
     */
    public function versions(string $projectId, array $gameVersions, int $limit = 25): array;

    /**
     * @param  string[]  $gameVersions
     * @return array<string, mixed>|null
     */
    public function latestVersion(string $projectId, string $currentVersionId, array $gameVersions): ?array;

    /**
     * Fetch display info (title, icon) for a set of project ids.
     *
     * @param  string[]  $ids
     * @return array<string, array{title: string, icon_url: string|null}>
     */
    public function projects(array $ids): array;
}
