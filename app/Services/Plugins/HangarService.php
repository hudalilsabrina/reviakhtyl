<?php

namespace App\Services\Plugins;

use Illuminate\Support\Facades\Http;

class HangarService implements PluginProviderInterface
{
    public const PROVIDER = 'hangar';

    private const API = 'https://hangar.papermc.io/api/v1';

    public function search(string $query, array $loaders, ?string $gameVersion, int $limit, int $offset): array
    {
        $response = Http::acceptJson()->timeout(15)->get(self::API.'/projects', [
            'q' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        if ($response->failed()) {
            return ['hits' => [], 'total' => 0];
        }

        return [
            'hits' => collect($response->json('result', []))->map(fn ($hit) => [
                'id' => $hit['namespace']['owner'].'/'.$hit['namespace']['slug'],
                'slug' => $hit['namespace']['slug'],
                'title' => $hit['name'],
                'description' => $hit['description'] ?? '',
                'author' => $hit['namespace']['owner'],
                'icon_url' => $hit['avatarUrl'] ?? null,
                'downloads' => $hit['stats']['downloads'] ?? 0,
            ])->all(),
            'total' => $response->json('pagination.count', 0),
        ];
    }

    public function resolveVersion(string $projectId, array $loaders, ?string $gameVersion): ?array
    {
        // Hangar platforms are PAPER/VELOCITY/WATERFALL; map loader filters loosely.
        $platforms = collect($loaders)->map(fn ($l) => match ($l) {
            'velocity' => 'VELOCITY',
            'bungeecord' => 'WATERFALL',
            default => 'PAPER',
        })->unique()->values();

        $response = Http::acceptJson()->timeout(15)
            ->get(self::API.'/projects/'.$projectId.'/versions', ['limit' => 25]);

        if ($response->failed()) {
            return null;
        }

        $version = collect($response->json('result', []))->first(function ($v) use ($platforms, $gameVersion) {
            $deps = collect($v['platformDependencies'] ?? []);
            if ($platforms->isNotEmpty() && $deps->isNotEmpty() && $deps->keys()->intersect($platforms)->isEmpty()) {
                return false;
            }
            if ($gameVersion) {
                $supported = $deps->flatten(1);
                if ($supported->isNotEmpty() && ! $supported->contains($gameVersion)) {
                    return false;
                }
            }

            return true;
        });

        if (! $version) {
            return null;
        }

        $download = $version['downloads'][strtoupper($version['platforms'][0] ?? 'PAPER')]['downloadUrl']
            ?? collect($version['downloads'] ?? [])->first()['downloadUrl'] ?? null;

        if (! $download) {
            return null;
        }

        return [
            'id' => (string) $version['id'],
            'version_number' => $version['name'],
            'file_name' => $version['downloads'][array_key_first($version['downloads'])]['fileInfo']['name']
                ?? $version['name'].'.jar',
            'download_url' => $download,
            'game_versions' => collect($version['platformDependencies'] ?? [])->flatten(1)->all(),
            'loaders' => $version['platforms'] ?? [],
        ];
    }

    public function latestVersion(string $projectId, string $currentVersionId, array $loaders, ?string $gameVersion): ?array
    {
        $version = $this->resolveVersion($projectId, $loaders, $gameVersion);

        if (! $version || $version['id'] === $currentVersionId) {
            return null;
        }

        return $version;
    }
}
