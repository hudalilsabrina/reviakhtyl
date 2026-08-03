<?php

namespace App\Services\Datapacks;

/**
 * Datapack-specific Modrinth wrapper. Reuses the Modrinth HTTP client but
 * searches the "datapack" project type instead of "mod".
 */
class ModrinthService implements DatapackProviderInterface
{
    public const PROVIDER = 'modrinth';

    private \App\Services\Mods\ModrinthService $delegate;

    public function __construct(\App\Services\Mods\ModrinthService $delegate)
    {
        $this->delegate = $delegate;
    }

    public function search(string $query, array $gameVersions, int $limit, int $offset, string $sort = 'relevance'): array
    {
        $facets = [['project_type:datapack']];
        if ($gameVersions) {
            foreach ($gameVersions as $gv) {
                $facets[] = ['versions:'.$gv];
            }
        }

        $index = match ($sort) {
            'downloads' => 'downloads',
            'updated' => 'updated',
            default => 'relevance',
        };

        $response = \Illuminate\Support\Facades\Http::acceptJson()->timeout(15)
            ->get(\App\Services\Mods\ModrinthService::API.'/search', [
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

    public function resolveVersion(string $projectId, array $gameVersions): ?array
    {
        return $this->versions($projectId, $gameVersions, 100)[0] ?? null;
    }

    public function versions(string $projectId, array $gameVersions, int $limit = 25): array
    {
        $params = [];
        if ($gameVersions) {
            $params['game_versions'] = json_encode(array_values($gameVersions));
        }

        $response = \Illuminate\Support\Facades\Http::acceptJson()->timeout(15)
            ->get(\App\Services\Mods\ModrinthService::API.'/project/'.$projectId.'/version', $params);

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
        return $this->delegate->projects($ids);
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
            'dependencies' => collect($version['dependencies'] ?? [])
                ->filter(fn ($d) => in_array($d['dependency_type'] ?? '', ['required', 'optional']) && ($d['project_id'] ?? null))
                ->map(fn ($d) => [
                    'project_id' => $d['project_id'],
                    'required' => $d['dependency_type'] === 'required',
                ])
                ->values()
                ->all(),
        ];
    }
}
