<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Http;

class ModrinthService implements PluginProviderInterface
{
    public const PROVIDER = 'modrinth';

    private const API = 'https://api.modrinth.com/v2';

    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset): array
    {
        $facets = [['project_type:plugin']];
        if ($gameVersion) {
            $facets[] = ['versions:'.$gameVersion];
        }
        if ($loaders) {
            $facets[] = array_map(fn ($l) => 'categories:'.$l, $loaders);
        }

        $response = Http::acceptJson()->timeout(15)->get(self::API.'/search', [
            'query' => $query,
            'limit' => $limit,
            'offset' => $offset,
            'index' => 'relevance',
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
            return null;
        }

        // API returns newest first.
        $versions = collect($response->json());
        $version = $versions->first();

        return $version ? $this->mapVersion($version) : null;
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
