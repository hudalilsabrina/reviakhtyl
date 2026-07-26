<?php

namespace App\Services\Plugins;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Plugins\PluginUpToDateException;
use App\Models\Server;
use App\Models\ServerPlugin;
use App\Repositories\Agent\DaemonFileRepository;
use Illuminate\Support\Facades\Cache;

class PluginManagerService
{
    public const LOADERS = [
        'paper' => ['paper', 'spigot', 'bukkit'],
        'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
        'spigot' => ['spigot', 'bukkit'],
        'bukkit' => ['bukkit'],
        'velocity' => ['velocity'],
        'bungeecord' => ['bungeecord'],
        'waterfall' => ['bungeecord'],
        'folia' => ['folia', 'paper', 'spigot', 'bukkit'],
    ];

    /** @var array<string, PluginProviderInterface> */
    private array $providers;

    /** @var array<int, int>|null */
    private ?array $eggIdsCache = null;

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private SettingsRepositoryInterface $settings,
        ModrinthService $modrinth,
        HangarService $hangar,
        SpigetService $spiget,
    ) {
        $this->providers = [
            ModrinthService::PROVIDER => $modrinth,
            HangarService::PROVIDER => $hangar,
            SpigetService::PROVIDER => $spiget,
        ];
    }

    /**
     * Whether the plugin installer is enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->enabledEggIds(), true);
    }

    /**
     * Egg IDs allowed to use the plugin installer, from settings.
     *
     * @return array<int, int>
     */
    public function enabledEggIds(): array
    {
        if ($this->eggIdsCache !== null) {
            return $this->eggIdsCache;
        }

        $value = $this->settings->get('settings::panel:plugins:egg_ids', null);

        if (empty($value)) {
            return $this->eggIdsCache = [];
        }

        if (is_array($value)) {
            return $this->eggIdsCache = array_map('intval', $value);
        }

        return $this->eggIdsCache = array_map('intval', json_decode($value, true) ?: []);
    }

    public function provider(string $name): PluginProviderInterface
    {
        $provider = $this->providers[$name] ?? null;

        if (! $provider) {
            throw new DisplayException('Unknown plugin provider.');
        }

        return $provider;
    }

    public function providerNames(): array
    {
        return array_keys($this->providers);
    }

    public function loaders(Server $server): array
    {
        $buildType = strtolower($this->variable($server, 'BUILD_TYPE') ?? '');

        return self::LOADERS[$buildType] ?? ['paper', 'spigot', 'bukkit'];
    }

    public function gameVersion(Server $server): ?string
    {
        $version = $this->variable($server, 'MINECRAFT_VERSION');

        if (! $version || in_array(strtolower($version), ['latest', '']) || str_starts_with($version, 'latest')) {
            return null;
        }

        return $version;
    }

    /**
     * Same plugin already installed from a different provider, matched by slug/title.
     * For manual plugins, also checks against jar metadata to catch plugin.yml name mismatches.
     */
    public function crossProviderDuplicate(Server $server, string $providerName, string $slug): ?ServerPlugin
    {
        $normalized = strtolower($slug);

        foreach ($server->plugins as $p) {
            if ($p->provider === $providerName) {
                continue;
            }

            // Check slug and title
            if (strtolower($p->slug) === $normalized || strtolower($p->title) === $normalized) {
                return $p;
            }

            // For manual plugins, extract jar metadata and compare plugin.yml name
            if ($p->provider === 'manual' && method_exists($this, 'jarService')) {
                try {
                    $jarService = app(PluginJarService::class);
                    $meta = $jarService->metadata($server, $p->file_name, 0);
                    if (strtolower($meta['slug']) === $normalized || strtolower($meta['title']) === $normalized) {
                        return $p;
                    }
                } catch (\Exception) {
                    // Jar read failed; skip metadata check
                }
            }
        }

        return null;
    }

    /**
     * Install or update a provider project onto the server.
     */
    public function install(Server $server, string $providerName, string $projectId, ?string $title = null, ?string $iconUrl = null, ?string $versionId = null, ?string $slug = null): ServerPlugin
    {
        $provider = $this->provider($providerName);
        $existing = $server->plugins()
            ->where('provider', $providerName)
            ->where('project_id', $projectId)
            ->first();

        $version = $versionId
            ? collect($provider->versions($projectId, $this->loaders($server), $this->gameVersion($server), 100))
                ->firstWhere('id', $versionId)
            : $provider->resolveVersion($projectId, $this->loaders($server), $this->gameVersion($server));

        if (! $version) {
            throw new DisplayException('No compatible plugin version found for this server.');
        }

        $this->pull($server, $version, $existing);

        $plugin = $server->plugins()->updateOrCreate(
            ['provider' => $providerName, 'project_id' => $projectId],
            [
                'slug' => $slug ?? $projectId,
                'title' => $title ?? $projectId,
                'version_id' => $version['id'],
                'version_number' => $version['version_number'],
                'file_name' => $version['file_name'],
                'icon_url' => $iconUrl,
            ]
        );

        $this->clearPluginCache($server);

        return $plugin;
    }

    public function update(Server $server, ServerPlugin $plugin): ServerPlugin
    {
        $latest = $this->provider($plugin->provider)->latestVersion(
            $plugin->project_id,
            $plugin->version_id,
            $this->loaders($server),
            $this->gameVersion($server)
        );

        if (! $latest) {
            throw new PluginUpToDateException();
        }

        $this->pull($server, $latest, $plugin);

        $plugin->update([
            'version_id' => $latest['id'],
            'version_number' => $latest['version_number'],
            'file_name' => $latest['file_name'],
        ]);

        $this->clearPluginCache($server);

        return $plugin->refresh();
    }

    public function delete(Server $server, ServerPlugin $plugin): void
    {
        $this->fileRepository->setServer($server)->deleteFiles('/plugins', [$plugin->file_name]);
        // Also remove a previously disabled copy.
        $this->fileRepository->deleteFiles('/plugins', [$plugin->file_name.'.disabled']);

        $plugin->delete();

        $this->clearPluginCache($server);
    }

    public function toggle(Server $server, ServerPlugin $plugin): ServerPlugin
    {
        $disabled = str_ends_with($plugin->file_name, '.disabled');
        $from = $plugin->file_name;
        $to = $disabled ? substr($from, 0, -9) : $from.'.disabled';

        $this->fileRepository->setServer($server)->renameFiles('/plugins', [
            ['from' => $from, 'to' => $to],
        ]);

        $plugin->update(['file_name' => $to]);

        $this->clearPluginCache($server);

        return $plugin->refresh();
    }

    private function pull(Server $server, array $version, ?ServerPlugin $existing): void
    {
        $repository = $this->fileRepository->setServer($server);

        // Remove previous jar when the file name changes between versions.
        if ($existing && $existing->file_name !== $version['file_name']) {
            $repository->deleteFiles('/plugins', [$existing->file_name, $existing->file_name.'.disabled']);
        }

        $repository->pull($version['download_url'], '/plugins', [
            'filename' => $version['file_name'],
            'foreground' => true,
        ]);

        // Verify file landed to prevent ghost track
        $files = $repository->getDirectory('/plugins');
        $found = collect($files)->contains('name', $version['file_name']);

        if (! $found) {
            throw new DisplayException('Download completed but file not found in /plugins directory.');
        }
    }

    private function variable(Server $server, string $key): ?string
    {
        $variable = $server->variables->firstWhere('env_variable', $key);

        if (! $variable) {
            return null;
        }

        $value = $variable->server_value ?? $variable->default_value;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function clearPluginCache(Server $server): void
    {
        Cache::forget(sprintf('server:%d:plugins-dir', $server->id));
    }
}
