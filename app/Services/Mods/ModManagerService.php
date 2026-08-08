<?php

namespace App\Services\Mods;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Mods\ModUpToDateException;
use App\Models\Server;
use App\Models\ServerMod;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Security\FileScanService;
use Illuminate\Support\Facades\Cache;

class ModManagerService
{
    public const MOD_LOADERS = ['fabric', 'forge', 'neoforge', 'quilt'];

    private const MAX_SIZE = 64 * 1024 * 1024;

    /** @var array<string, ModProviderInterface> */
    private array $providers;

    /** @var array<int, int>|null */
    private ?array $eggIdsCache = null;

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private SettingsRepositoryInterface $settings,
        private FileScanService $fileScanService,
        ModrinthService $modrinth,
        CurseForgeService $curseforge,
    ) {
        $this->providers = [
            ModrinthService::PROVIDER => $modrinth,
            CurseForgeService::PROVIDER => $curseforge,
        ];
    }

    /**
     * Whether the mod installer is enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->enabledEggIds(), true);
    }

    /**
     * Egg IDs allowed to use the mod installer, from settings.
     *
     * @return array<int, int>
     */
    public function enabledEggIds(): array
    {
        if ($this->eggIdsCache !== null) {
            return $this->eggIdsCache;
        }

        return $this->eggIdsCache = Cache::remember('panel:mods:egg_ids_cache', now()->addHours(1), function () {
            $value = $this->settings->get('settings::panel:mods:egg_ids', null);

            if (empty($value)) {
                return [];
            }

            if (is_array($value)) {
                return array_map('intval', $value);
            }

            return array_map('intval', json_decode($value, true) ?: []);
        });
    }

    public function provider(string $name): ModProviderInterface
    {
        $provider = $this->providers[$name] ?? null;

        if (! $provider) {
            throw new DisplayException('Unknown mod provider.');
        }

        return $provider;
    }

    public function providerNames(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Detect mod loaders for this server.
     * Prefer MOD_LOADER / LOADER; else BUILD_TYPE if it's a mod loader;
     * else egg name contains fabric|forge|neoforge|quilt; else all four.
     * NeoForge also searches forge for dual-tagged mods.
     */
    public function loaders(Server $server): array
    {
        $explicit = strtolower(
            $this->variable($server, 'MOD_LOADER')
                ?? $this->variable($server, 'LOADER')
                ?? ''
        );

        if ($explicit && in_array($explicit, self::MOD_LOADERS, true)) {
            return $this->expandLoaders([$explicit]);
        }

        $buildType = strtolower($this->variable($server, 'BUILD_TYPE') ?? '');
        if ($buildType && in_array($buildType, self::MOD_LOADERS, true)) {
            return $this->expandLoaders([$buildType]);
        }

        $server->loadMissing('egg');
        $eggName = strtolower($server->egg->name ?? '');
        $fromEgg = [];
        foreach (self::MOD_LOADERS as $loader) {
            if (str_contains($eggName, $loader)) {
                $fromEgg[] = $loader;
            }
        }

        if ($fromEgg) {
            return $this->expandLoaders($fromEgg);
        }

        return self::MOD_LOADERS;
    }

    public function gameVersion(Server $server): ?string
    {
        $version = $this->variable($server, 'MINECRAFT_VERSION')
            ?? $this->variable($server, 'MC_VERSION');

        if (! $version || in_array(strtolower($version), ['latest', '']) || str_starts_with($version, 'latest')) {
            return null;
        }

        return $version;
    }

    /**
     * Same mod already installed from a different provider, matched by slug/title.
     */
    public function crossProviderDuplicate(Server $server, string $providerName, string $slug): ?ServerMod
    {
        $normalized = strtolower($slug);

        foreach ($server->mods as $m) {
            if ($m->provider === $providerName) {
                continue;
            }

            if (strtolower($m->slug) === $normalized || strtolower($m->title) === $normalized) {
                return $m;
            }

            if ($m->provider === 'manual') {
                try {
                    $meta = app(ModJarService::class)->metadata($server, $m->file_name, 0);
                    if (strtolower($meta['slug']) === $normalized || strtolower($meta['title']) === $normalized) {
                        return $m;
                    }
                } catch (\Exception) {
                    // Jar read failed; skip metadata check
                }
            }
        }

        return null;
    }

    public function install(Server $server, string $providerName, string $projectId, ?string $title = null, ?string $iconUrl = null, ?string $versionId = null, ?string $slug = null): ServerMod
    {
        $provider = $this->provider($providerName);
        $existing = $server->mods()
            ->where('provider', $providerName)
            ->where('project_id', $projectId)
            ->first();

        $version = $versionId
            ? collect($provider->versions($projectId, $this->loaders($server), $this->gameVersion($server), 100))
                ->firstWhere('id', $versionId)
            : $provider->resolveVersion($projectId, $this->loaders($server), $this->gameVersion($server));

        if (! $version) {
            throw new DisplayException('No compatible mod version found for this server.');
        }

        $this->pull($server, $version, $existing);

        $mod = $server->mods()->updateOrCreate(
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

        $this->clearModCache($server);

        return $mod;
    }

    public function update(Server $server, ServerMod $mod): ServerMod
    {
        $latest = $this->provider($mod->provider)->latestVersion(
            $mod->project_id,
            $mod->version_id,
            $this->loaders($server),
            $this->gameVersion($server)
        );

        if (! $latest) {
            throw new ModUpToDateException();
        }

        $this->pull($server, $latest, $mod);

        $mod->update([
            'version_id' => $latest['id'],
            'version_number' => $latest['version_number'],
            'file_name' => $latest['file_name'],
        ]);

        $this->clearModCache($server);

        return $mod->refresh();
    }

    public function delete(Server $server, ServerMod $mod): void
    {
        $this->fileRepository->setServer($server)->deleteFiles('/mods', [$mod->file_name]);
        $this->fileRepository->deleteFiles('/mods', [$mod->file_name.'.disabled']);

        $mod->delete();

        $this->clearModCache($server);
    }

    public function toggle(Server $server, ServerMod $mod): ServerMod
    {
        $disabled = str_ends_with($mod->file_name, '.disabled');
        $from = $mod->file_name;
        $to = $disabled ? substr($from, 0, -9) : $from.'.disabled';

        $this->fileRepository->setServer($server)->renameFiles('/mods', [
            ['from' => $from, 'to' => $to],
        ]);

        $mod->update(['file_name' => $to]);

        $this->clearModCache($server);

        return $mod->refresh();
    }

    private function pull(Server $server, array $version, ?ServerMod $existing): void
    {
        $repository = $this->fileRepository->setServer($server);

        if ($existing && $existing->file_name !== $version['file_name']) {
            $repository->deleteFiles('/mods', [$existing->file_name, $existing->file_name.'.disabled']);
        }

        $repository->pull($version['download_url'], '/mods', [
            'filename' => $version['file_name'],
            'foreground' => true,
        ]);

        $files = $repository->getDirectory('/mods');
        $found = collect($files)->contains('name', $version['file_name']);

        if (! $found) {
            throw new DisplayException('Download completed but file not found in /mods directory.');
        }

        $this->assertCleanScan($server, '/mods/'.$version['file_name']);
    }

    private function assertCleanScan(Server $server, string $remotePath): void
    {
        $scan = $this->fileScanService->scanRemoteFile($this->fileRepository, $server, $remotePath);

        if ($scan->isInfected() || ($scan->isError() && $this->fileScanService->isStrict())
            || ($scan->isError() && str_contains((string) $scan->getMessage(), 'Failed to fetch remote file'))) {
            $this->fileRepository->setServer($server)->deleteFiles(dirname($remotePath), [basename($remotePath)]);

            if ($scan->isInfected()) {
                throw new DisplayException("Downloaded file failed virus scan: {$scan->getSignature()}");
            }

            throw new DisplayException('File scanner error: '.$scan->getMessage());
        }
    }

    /** NeoForge dual-tags many mods as forge. */
    private function expandLoaders(array $loaders): array
    {
        if (in_array('neoforge', $loaders, true) && ! in_array('forge', $loaders, true)) {
            $loaders[] = 'forge';
        }

        return array_values(array_unique($loaders));
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

    private function clearModCache(Server $server): void
    {
        Cache::forget(sprintf('server:%d:mods-dir', $server->id));
    }
}
