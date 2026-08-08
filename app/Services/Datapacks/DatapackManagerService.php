<?php

namespace App\Services\Datapacks;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Datapacks\DatapackUpToDateException;
use App\Models\Server;
use App\Models\ServerDatapack;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Security\FileScanService;
use Illuminate\Support\Facades\Cache;

class DatapackManagerService
{
    /** @var array<string, DatapackProviderInterface> */
    private array $providers;

    /** @var array<int, int>|null */
    private ?array $eggIdsCache = null;

    public function __construct(
        private DaemonFileRepository $fileRepository,
        private SettingsRepositoryInterface $settings,
        private FileScanService $fileScanService,
        private DatapackZipService $zipService,
        ModrinthService $modrinth,
        CurseForgeService $curseforge,
    ) {
        $this->providers = [
            ModrinthService::PROVIDER => $modrinth,
            CurseForgeService::PROVIDER => $curseforge,
        ];
    }

    /**
     * Whether the datapack installer is enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return in_array($server->egg_id, $this->enabledEggIds(), true);
    }

    /**
     * Egg IDs allowed to use the datapack installer, from settings.
     *
     * @return array<int, int>
     */
    public function enabledEggIds(): array
    {
        if ($this->eggIdsCache !== null) {
            return $this->eggIdsCache;
        }

        return $this->eggIdsCache = Cache::remember('panel:datapacks:egg_ids_cache', now()->addHours(1), function () {
            $value = $this->settings->get('settings::panel:datapacks:egg_ids', null);

            if (empty($value)) {
                return [];
            }

            if (is_array($value)) {
                return array_map('intval', $value);
            }

            return array_map('intval', json_decode($value, true) ?: []);
        });
    }

    public function provider(string $name): DatapackProviderInterface
    {
        $provider = $this->providers[$name] ?? null;

        if (! $provider) {
            throw new DisplayException('Unknown datapack provider.');
        }

        return $provider;
    }

    public function providerNames(): array
    {
        return array_keys($this->providers);
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
     * Same datapack already installed from a different provider, matched by
     * slug/title. Manual datapacks are compared via their zip metadata.
     */
    public function crossProviderDuplicate(Server $server, string $providerName, string $slug): ?ServerDatapack
    {
        $normalized = strtolower($slug);

        foreach ($server->datapacks as $datapack) {
            if ($datapack->provider === $providerName) {
                continue;
            }

            if (strtolower($datapack->slug) === $normalized || strtolower($datapack->title) === $normalized) {
                return $datapack;
            }

            if ($datapack->provider === 'manual') {
                try {
                    $meta = $this->zipService->parsePackMcmeta($server, $datapack->file_name, 0);
                    if (strtolower($meta['slug']) === $normalized || strtolower($meta['title']) === $normalized) {
                        return $datapack;
                    }
                } catch (\Exception) {
                    // Zip read failed; skip metadata check
                }
            }
        }

        return null;
    }

    public function install(Server $server, string $providerName, string $projectId, ?string $title = null, ?string $iconUrl = null, ?string $versionId = null, ?string $slug = null): ServerDatapack
    {
        $provider = $this->provider($providerName);
        $existing = $server->datapacks()
            ->where('provider', $providerName)
            ->where('project_id', $projectId)
            ->first();

        $gameVersion = $this->gameVersion($server);
        $gameVersions = $gameVersion ? [$gameVersion] : [];

        $version = $versionId
            ? collect($provider->versions($projectId, $gameVersions, 100))->firstWhere('id', $versionId)
            : $provider->resolveVersion($projectId, $gameVersions);

        if (! $version) {
            throw new DisplayException('No compatible datapack version found for this server.');
        }

        $this->pull($server, $version, $existing);

        $datapack = $server->datapacks()->updateOrCreate(
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

        $this->clearDatapackCache($server);

        return $datapack;
    }

    public function update(Server $server, ServerDatapack $datapack): ServerDatapack
    {
        $gameVersion = $this->gameVersion($server);
        $latest = $this->provider($datapack->provider)->latestVersion(
            $datapack->project_id,
            $datapack->version_id,
            $gameVersion ? [$gameVersion] : []
        );

        if (! $latest) {
            throw new DatapackUpToDateException();
        }

        $this->pull($server, $latest, $datapack);

        $datapack->update([
            'version_id' => $latest['id'],
            'version_number' => $latest['version_number'],
            'file_name' => $latest['file_name'],
        ]);

        $this->clearDatapackCache($server);

        return $datapack->refresh();
    }

    public function delete(Server $server, ServerDatapack $datapack): void
    {
        $this->fileRepository->setServer($server)->deleteFiles('/datapacks', [$datapack->file_name]);
        $this->fileRepository->deleteFiles('/datapacks', [$datapack->file_name.'.disabled']);

        $datapack->delete();

        $this->clearDatapackCache($server);
    }

    public function toggle(Server $server, ServerDatapack $datapack): ServerDatapack
    {
        $disabled = str_ends_with($datapack->file_name, '.disabled');
        $from = $datapack->file_name;
        $to = $disabled ? substr($from, 0, -9) : $from.'.disabled';

        $this->fileRepository->setServer($server)->renameFiles('/datapacks', [
            ['from' => $from, 'to' => $to],
        ]);

        $datapack->update(['file_name' => $to]);

        $this->clearDatapackCache($server);

        return $datapack->refresh();
    }

    private function pull(Server $server, array $version, ?ServerDatapack $existing): void
    {
        $repository = $this->fileRepository->setServer($server);

        if ($existing && $existing->file_name !== $version['file_name']) {
            $repository->deleteFiles('/datapacks', [$existing->file_name, $existing->file_name.'.disabled']);
        }

        $repository->pull($version['download_url'], '/datapacks', [
            'filename' => $version['file_name'],
            'foreground' => true,
        ]);

        $files = $repository->getDirectory('/datapacks');
        $found = collect($files)->contains('name', $version['file_name']);

        if (! $found) {
            throw new DisplayException('Download completed but file not found in /datapacks directory.');
        }

        $this->assertCleanScan($server, '/datapacks', $version['file_name']);

        // Verify pack.mcmeta exists; delete the bad ZIP if not found.
        if (! $this->zipService->hasPackMcmeta($server, $version['file_name'])) {
            $repository->deleteFiles('/datapacks', [$version['file_name']]);

            throw new DisplayException('Downloaded file is not a valid datapack (no pack.mcmeta).');
        }
    }

    private function assertCleanScan(Server $server, string $directory, string $fileName): void
    {
        $scan = $this->fileScanService->scanRemoteFile($this->fileRepository, $server, $directory.'/'.$fileName);

        if ($scan->isInfected() || ($scan->isError() && $this->fileScanService->isStrict())
            || ($scan->isError() && str_contains((string) $scan->getMessage(), 'Failed to fetch remote file'))) {
            $this->fileRepository->setServer($server)->deleteFiles($directory, [$fileName]);

            if ($scan->isInfected()) {
                throw new DisplayException("Downloaded file failed virus scan: {$scan->getSignature()}");
            }

            throw new DisplayException('File scanner error: '.$scan->getMessage());
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

    private function clearDatapackCache(Server $server): void
    {
        Cache::forget(sprintf('server:%d:datapacks-dir', $server->id));
    }
}
