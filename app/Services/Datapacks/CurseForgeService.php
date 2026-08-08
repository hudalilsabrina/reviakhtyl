<?php

namespace App\Services\Datapacks;

use App\Contracts\Repository\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Http;

/**
 * CurseForge datapack provider (classId 47).
 *
 * Shares the same API key configured for the mod installer, but filters on
 * the CurseForge "datapack" category and has no loader concept.
 */
class CurseForgeService implements DatapackProviderInterface
{
    public const PROVIDER = 'curseforge';

    private const API = 'https://api.curseforge.com';

    private const MINECRAFT_GAME_ID = 432;

    /** CurseForge classId for datapacks. */
    private const DATAPACK_CLASS_ID = 47;

    public function __construct(private SettingsRepositoryInterface $settings) {}

    public function search(string $query, array $gameVersions, int $limit, int $offset, string $sort = 'relevance'): array
    {
        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key');

        if (! $apiKey) {
            return ['hits' => [], 'total' => 0];
        }

        $params = [
            'gameId' => self::MINECRAFT_GAME_ID,
            'classId' => self::DATAPACK_CLASS_ID,
            'searchFilter' => $query,
            'index' => $offset,
            'pageSize' => min($limit, 50),
            'sortField' => $this->mapSortField($sort),
            'sortOrder' => 'desc',
        ];

        if ($gameVersions) {
            $params['gameVersion'] = $gameVersions[0];
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withHeaders(['x-api-key' => $apiKey])
            ->get(self::API.'/v1/mods/search', $params);

        if ($response->failed()) {
            return ['hits' => [], 'total' => 0];
        }

        $data = $response->json('data', []);

        return [
            'hits' => collect($data)->map(fn ($mod) => [
                'id' => (string) $mod['id'],
                'slug' => $mod['slug'],
                'title' => $mod['name'],
                'description' => $mod['summary'] ?? '',
                'author' => $mod['authors'][0]['name'] ?? '',
                'icon_url' => $mod['logo']['url'] ?? null,
                'downloads' => $mod['downloadCount'] ?? 0,
            ])->all(),
            'total' => $response->json('pagination.totalCount', 0),
        ];
    }

    public function resolveVersion(string $projectId, array $gameVersions): ?array
    {
        return $this->versions($projectId, $gameVersions, 1)[0] ?? null;
    }

    public function versions(string $projectId, array $gameVersions, int $limit = 25): array
    {
        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key');

        if (! $apiKey) {
            return [];
        }

        $params = ['pageSize' => min($limit, 50)];

        if ($gameVersions) {
            $params['gameVersion'] = $gameVersions[0];
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withHeaders(['x-api-key' => $apiKey])
            ->get(self::API."/v1/mods/{$projectId}/files", $params);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('data', []))
            ->map(fn ($file) => $this->mapVersion($file))
            ->filter()
            ->take($limit)
            ->values()
            ->all();
    }

    public function latestVersion(string $projectId, string $currentVersionId, array $gameVersions): ?array
    {
        $version = $this->resolveVersion($projectId, $gameVersions);

        if (! $version || $version['id'] === $currentVersionId) {
            return null;
        }

        return $version;
    }

    public function projects(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key');

        if (! $apiKey) {
            return [];
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withHeaders(['x-api-key' => $apiKey])
            ->post(self::API.'/v1/mods', ['modIds' => array_map('intval', $ids)]);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json('data', []))
            ->mapWithKeys(fn ($mod) => [(string) $mod['id'] => [
                'title' => $mod['name'],
                'icon_url' => $mod['logo']['url'] ?? null,
            ]])
            ->all();
    }

    private function mapVersion(array $file): ?array
    {
        if (! isset($file['downloadUrl'])) {
            return null;
        }

        return [
            'id' => (string) $file['id'],
            'version_number' => $file['displayName'] ?? $file['fileName'],
            'file_name' => $file['fileName'],
            'download_url' => $file['downloadUrl'],
            'game_versions' => collect($file['gameVersions'] ?? [])->filter(fn ($v) => preg_match('/^\d+\.\d+/', $v))->values()->all(),
            'loaders' => [],
            'dependencies' => [],
        ];
    }

    private function mapSortField(string $sort): int
    {
        return match ($sort) {
            'downloads' => 6, // TotalDownloads
            'updated' => 2, // LastUpdated
            default => 1, // Featured
        };
    }
}
