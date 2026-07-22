<?php

namespace App\Services\Plugins;

use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\ServerPlugin;
use App\Repositories\Agent\DaemonFileRepository;

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

    public function __construct(
        private DaemonFileRepository $fileRepository,
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
     * Install or update a provider project onto the server.
     */
    public function install(Server $server, string $providerName, string $projectId, ?string $title = null, ?string $iconUrl = null): ServerPlugin
    {
        $provider = $this->provider($providerName);
        $existing = $server->plugins()
            ->where('provider', $providerName)
            ->where('project_id', $projectId)
            ->first();

        $version = $provider->resolveVersion($projectId, $this->loaders($server), $this->gameVersion($server));

        if (! $version) {
            throw new DisplayException('No compatible plugin version found for this server.');
        }

        $this->pull($server, $version, $existing);

        $plugin = $server->plugins()->updateOrCreate(
            ['provider' => $providerName, 'project_id' => $projectId],
            [
                'slug' => $projectId,
                'title' => $title ?? $projectId,
                'version_id' => $version['id'],
                'version_number' => $version['version_number'],
                'file_name' => $version['file_name'],
                'icon_url' => $iconUrl,
            ]
        );

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
            throw new DisplayException('Plugin is already up to date.');
        }

        $this->pull($server, $latest, $plugin);

        $plugin->update([
            'version_id' => $latest['id'],
            'version_number' => $latest['version_number'],
            'file_name' => $latest['file_name'],
        ]);

        return $plugin->refresh();
    }

    public function delete(Server $server, ServerPlugin $plugin): void
    {
        $this->fileRepository->setServer($server)->deleteFiles('/plugins', [$plugin->file_name]);
        // Also remove a previously disabled copy.
        $this->fileRepository->deleteFiles('/plugins', [$plugin->file_name.'.disabled']);

        $plugin->delete();
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
}
