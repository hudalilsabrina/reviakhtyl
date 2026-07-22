<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Http;

class SpigetService implements PluginProviderInterface
{
    public const PROVIDER = 'spiget';

    private const API = 'https://api.spiget.org/v2';

    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset, string $sort = 'relevance'): array
    {
        $spigetSort = match ($sort) {
            'updated' => '-updateDate',
            default => '-downloads',
        };

        $response = Http::acceptJson()->timeout(15)->get(self::API.'/search/resources/'.urlencode($query), [
            'size' => $limit,
            'page' => intdiv($offset, max($limit, 1)) + 1,
            'sort' => $spigetSort,
            'fields' => 'id,name,tag,icon,downloads,author',
        ]);

        if ($response->failed() || ! is_array($response->json())) {
            return ['hits' => [], 'total' => 0];
        }

        $total = (int) ($response->header('x-total-count')[0] ?? 0);

        return [
            'hits' => collect($response->json())->map(fn ($hit) => [
                'id' => (string) $hit['id'],
                'slug' => (string) $hit['id'],
                'title' => $hit['name'],
                'description' => $hit['tag'] ?? '',
                'author' => isset($hit['author']['id']) ? (string) $hit['author']['id'] : '',
                'icon_url' => isset($hit['icon']['url']) ? 'https://www.spigotmc.org/'.$hit['icon']['url'] : null,
                'downloads' => $hit['downloads'] ?? 0,
            ])->all(),
            // Spiget omits the header for single-page results.
            'total' => $total ?: count($response->json()) + $offset,
        ];
    }

    public function resolveVersion(string $projectId, array $loaders, ?string $gameVersion): ?array
    {
        return $this->versions($projectId, $loaders, $gameVersion, 1)[0] ?? null;
    }

    public function versions(string $projectId, array $loaders, ?string $gameVersion, int $limit = 25): array
    {
        $response = Http::acceptJson()->timeout(15)
            ->get(self::API.'/resources/'.$projectId.'/versions', [
                'size' => $limit,
                'sort' => '-releaseDate',
            ]);

        if ($response->failed() || ! is_array($response->json())) {
            return [];
        }

        return collect($response->json())
            ->map(fn ($v) => [
                'id' => (string) $v['id'],
                'version_number' => $v['name'],
                'file_name' => $this->slug($v['name']).'.jar',
                'download_url' => self::API.'/resources/'.$projectId.'/versions/'.$v['id'].'/download',
                'game_versions' => [],
                'loaders' => ['spigot'],
            ])
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

    private function slug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return trim($slug, '-') ?: 'plugin';
    }
}
