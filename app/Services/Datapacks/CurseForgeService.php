<?php

namespace App\Services\Datapacks;

use App\Contracts\Repository\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Http;

/**
 * CurseForge datapack provider (classId 47).
 *
 * Reuses the same CurseForge API endpoint the mod installer uses, with
 * a datapack-specific classId filter.
 */
class CurseForgeService implements DatapackProviderInterface
{
    public const PROVIDER = 'curseforge';

    private const API = 'https://api.curseforge.com';

    private const MINECRAFT_GAME_ID = 432;

    /** CurseForge classId for datapacks */
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
                'author' => $mod['authors'][0]['name'] ?? 'Unknown',
                'icon_url' => $mod['logo']['thumbnailUrl'] ?? null,
                'downloads' => $mod['downloadCount'] ?? 0,
            ])->all(),
            'total' => $response->json('totalCount', 0),
        ];
    }

    public function resolveVersion(string $projectId, array $gameVersions): ?array
    {
        $versions = $this->versions($projectId, $gameVersions, 1);

        return $versions[0] ?? null;
    }

    public function latestVersion(string $projectId, string $currentVersionId, array $gameVersions): ?array
    {
        $versions = $this->versions($projectId, $gameVersions, 10);

        foreach ($versions as $v) {
            if ($v['id'] !== $currentVersionId) {
                return $v;
            }
        }

        return null;
    }

    public function versions(string $projectId, array $gameVersions, int $limit = 25): array
    {
        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key');

        if (! $apiKey) {
            return [];
        }

        $params = [
            'modId' => (int) $projectId,
            'pageSize' => $limit,
        ];

        if ($gameVersions) {
            $params['gameVersion'] = $gameVersions[0];
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withHeaders(['x-api-key' => $apiKey])
            ->get(self::API.'/v1/mods/'.(int) $projectId.'/files', $params);

        if ($response->failed()) {
            return [];
        }

        $data = $response->json('data', []);

        return collect($data)->map(fn ($file) => [
            'id' => (string) $file['id'],
            'version_number' => $file['displayName'] ?? $file['fileName'],
            'file_name' => $file['fileName'],
            'download_url' => $file['downloadUrl'],
            'game_versions' => $file['gameVersionStrings'] ?? [],
        ])->all();
    }

    public function projects(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key');

        if (! $apiKey) {
            return array_fill_keys($ids, ['title' => 'Unknown', 'icon_url' => null]);
        }

        $result = [];
        foreach ($ids as $id) {
            $response = Http::acceptJson()
                ->timeout(15)
                ->withHeaders(['x-api-key' => $apiKey])
                ->get(self::API.'/v1/mods/'.(int) $id);

            if ($response->failed()) {
                $result[$id] = ['title' => 'Unknown', 'icon_url' => null];
                continue;
            }

            $mod = $response->json('data', []);
            $result[$id] = [
                'title' => $mod['name'] ?? 'Unknown',
                'icon_url' => $mod['logo']['thumbnailUrl'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Map frontend sort key to CurseForge sort field.
     */
    private function mapSortField(string $sort): string
    {
        return match ($sort) {
            'downloads' => 'downloadCount',
            'updated' => 'dateModified',
            default => 'relevance',
        };
    }
}
