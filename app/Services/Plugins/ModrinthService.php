<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Http;

class ModrinthService implements PluginProviderInterface
{
    public const PROVIDER = 'modrinth';

    private const API = 'https://api.modrinth.com/v2';

    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance'): array
    {
        $facets = [['project_type:plugin']];
        if ($gameVersion) {
            $facets[] = ['versions:'.$gameVersion];
        }
        if ($loaders) {
            $facets[] = array_map(fn ($l) => 'categories:'.$l, $loaders);
        }

        $index = match ($sort) {
            'downloads' => 'downloads',
            'updated' => 'updated',
            default => 'relevance',
        };

        $response = Http::acceptJson()->timeout(15)->get(self::API.'/search', [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'index' => $index,
            'facets' => json_encode($facets),
        ]);

        if ($response->failed()) {
            return ['hits' => [], 'total' => 0];
        }

        return [
            'hits' => collect($response->json('hits', []))->map(fn ($hit) => [
                'id' => $hit['project_id'],
                'slug' => $hit['slug'],
                'title' => $hit['title'],
                'description' => $hit['description'] ?? '',
                'author' => $hit['author'] ?? '',
                'icon_url' => $hit['icon_url'] ?? null,
                'downloads' => $hit['downloads'] ?? 0,
            ])->all(),
            'total' => $response->json('total_hits', 0),
        ];
    }

    public function resolveVersion(string $projectId, array $loaders, ?string $gameVersion): ?array
    {
        // API returns newest first.
        return $this->versions($projectId, $loaders, $gameVersion, 100)[0] ?? null;
    }

    public function versions(string $projectId, array $loaders, ?string $gameVersion, int $limit = 25): array
    {
        $params = [];
        if ($loaders) {
            $params['loaders'] = json_encode($loaders);
        }
        if ($gameVersion) {
            $params['game_versions'] = json_encode([$gameVersion]);
        }

        $response = Http::acceptJson()->timeout(15)
            ->get(self::API.'/project/'.$projectId.'/version', $params);

        if ($response->failed()) {
            return [];
        }

        return collect($response->json())
            ->map(fn ($v) => $this->mapVersion($v))
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

    private function mapVersion(array $version): ?array
    {
        $file = collect($version['files'] ?? [])->firstWhere('primary', true)
            ?? ($version['files'][0] ?? null);

        if (! $file) {
            return null;
        }

        return [
            'id' => $version['id'],
            'version_number' => $version['version_number'],
            'file_name' => $file['filename'],
            'download_url' => $file['url'],
            'game_versions' => $version['game_versions'] ?? [],
            'loaders' => $version['loaders'] ?? [],
        ];
    }
}
