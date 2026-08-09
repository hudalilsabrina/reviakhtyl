<?php

namespace App\Services\Mods;

use App\Contracts\Repository\SettingsRepositoryInterface;
use Illuminate\Support\Facades\Http;

class CurseForgeService implements ModProviderInterface
{
    public const PROVIDER = 'curseforge';

    private const API = 'https://api.curseforge.com';

    private const MINECRAFT_GAME_ID = 432;

    public function __construct(private SettingsRepositoryInterface $settings) {}

    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance', bool $serverSide = true): array
    {
        return $this->doSearch($query, $loaders, $gameVersion, $limit, $offset, $sort, 6);
    }

    public function searchModpacks(string $query, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance'): array
    {
        return $this->doSearch($query, [], $gameVersion, $limit, $offset, $sort, 4471);
    }

    private function doSearch(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset, string $sort, int $classId): array
    {
        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key', null);

        if (! $apiKey) {
            return ['hits' => [], 'total' => 0];
        }

        $params = [
            'gameId' => self::MINECRAFT_GAME_ID,
            'classId' => $classId,
            'searchFilter' => $query,
            'index' => $offset,
            'pageSize' => min($limit, 50),
            'sortField' => $this->mapSortField($sort),
            'sortOrder' => 'desc',
        ];

        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }

        if ($loaders) {
            $params['modLoaderTypes'] = json_encode($this->mapLoaders($loaders));
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

    public function resolveVersion(string $projectId, array $loaders, ?string $gameVersion): ?array
    {
        return $this->versions($projectId, $loaders, $gameVersion, 1)[0] ?? null;
    }

    public function versions(string $projectId, array $loaders, ?string $gameVersion, int $limit = 25): array
    {
        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key', null);

        if (! $apiKey) {
            return [];
        }

        $params = ['pageSize' => min($limit, 50)];

        if ($gameVersion) {
            $params['gameVersion'] = $gameVersion;
        }

        if ($loaders) {
            $params['modLoaderType'] = $this->mapLoaders($loaders)[0] ?? null;
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

    public function latestVersion(string $projectId, string $currentVersionId, array $loaders, ?string $gameVersion): ?array
    {
        $version = $this->resolveVersion($projectId, $loaders, $gameVersion);

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

        $apiKey = $this->settings->get('settings::panel:mods:curseforge_api_key', null);

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
                'id' => (string) $mod['id'],
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
            'loaders' => $this->extractLoaders($file),
            'dependencies' => collect($file['dependencies'] ?? [])
                ->filter(fn ($d) => in_array($d['relationType'] ?? 0, [1, 2, 3])) // 1=embedded, 2=optional, 3=required
                ->map(fn ($d) => [
                    'project_id' => (string) $d['modId'],
                    'required' => $d['relationType'] === 3,
                ])
                ->values()
                ->all(),
        ];
    }

    private function extractLoaders(array $file): array
    {
        $loaders = [];
        foreach ($file['gameVersions'] ?? [] as $version) {
            $lower = strtolower($version);
            if (in_array($lower, ['fabric', 'forge', 'neoforge', 'quilt'])) {
                $loaders[] = $lower;
            }
        }

        return array_values(array_unique($loaders));
    }

    private function mapSortField(string $sort): int
    {
        return match ($sort) {
            'downloads' => 6, // TotalDownloads
            'updated' => 2, // LastUpdated
            default => 1, // Featured
        };
    }

    private function mapLoaders(array $loaders): array
    {
        return collect($loaders)->map(fn ($loader) => match (strtolower($loader)) {
            'fabric' => 4,
            'forge' => 1,
            'neoforge' => 6,
            'quilt' => 5,
            default => null,
        })->filter()->values()->all();
    }
}
