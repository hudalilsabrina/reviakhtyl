<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Mods\BulkDeleteModsRequest;
use App\Http\Requests\Api\Client\Servers\Mods\BulkUpdateModsRequest;
use App\Http\Requests\Api\Client\Servers\Mods\DeleteModRequest;
use App\Http\Requests\Api\Client\Servers\Mods\InstallModRequest;
use App\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use App\Http\Requests\Api\Client\Servers\Mods\ToggleModRequest;
use App\Http\Requests\Api\Client\Servers\Mods\TrackModRequest;
use App\Http\Requests\Api\Client\Servers\Mods\UpdateModRequest;
use App\Models\Server;
use App\Models\ServerMod;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Mods\ModJarService;
use App\Services\Mods\ModManagerService;
use App\Services\Security\FileScanService;
use App\Transformers\Api\Client\ServerModTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class ModController extends ClientApiController
{
    public function __construct(private ModManagerService $manager, private ModJarService $jars)
    {
        parent::__construct();
    }

    public function index(SearchModsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $this->manager->provider($request->input('provider', 'modrinth'));

        return [
            'mods' => $this->fractal->collection($server->mods)
                ->transformWith($this->getTransformer(ServerModTransformer::class))
                ->toArray(),
            'game_version' => $this->manager->gameVersion($server),
            'loaders' => $this->manager->loaders($server),
        ];
    }

    public function search(SearchModsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $result = $this->manager->provider($request->input('provider'))->search(
            $request->input('query', '') ?? '',
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
            $request->integer('limit', 20),
            $request->integer('offset', 0),
            $request->input('sort', 'relevance') ?? 'relevance',
        );

        $installed = $server->mods
            ->mapWithKeys(fn ($m) => [$m->provider.':'.$m->project_id => $m->version_number]);

        return [
            'hits' => array_map(fn ($hit) => $hit + [
                'installed_version' => $installed[$request->input('provider').':'.$hit['id']] ?? null,
            ], $result['hits']),
            'total' => $result['total'],
        ];
    }

    public function versions(SearchModsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $request->validate(['project_id' => ['required', 'string', 'max:128']]);

        $provider = $this->manager->provider($request->input('provider'));
        $versions = $provider->versions(
            $request->input('project_id'),
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
        );

        $depIds = collect($versions)->pluck('dependencies')->flatten(1)->pluck('project_id')->unique()->all();
        $depProjects = $provider->projects($depIds);
        $installed = $server->mods
            ->filter(fn ($m) => $m->provider === $request->input('provider'))
            ->keyBy('project_id');

        $dependencies = [];
        foreach ($depIds as $id) {
            if (! isset($depProjects[$id])) {
                continue;
            }
            $existing = $installed->get($id);
            $dependencies[$id] = $depProjects[$id] + [
                'id' => $id,
                'installed' => $existing !== null,
            ];
        }

        return [
            'versions' => $versions,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Jars in /mods that are not tracked, with metadata parsed from their descriptors.
     */
    public function untracked(SearchModsRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $untracked = collect($this->jars->untracked($server))
            ->map(fn ($jar) => $jar + $this->jars->metadata($server, $jar['file_name'], $jar['size']))
            ->values()
            ->all();

        return new JsonResponse(['data' => $untracked]);
    }

    /**
     * Register an uploaded jar as a tracked "manual" mod.
     */
    public function register(TrackModRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $fileName = $request->input('file_name');
        $exists = $server->mods()->where('provider', 'manual')->where('file_name', $fileName)->exists();

        if ($exists) {
            throw new DisplayException('This file is already tracked.');
        }

        $fileExists = false;
        try {
            $files = $this->jars->untracked($server);
            $fileExists = collect($files)->contains('file_name', $fileName);

            if (! $fileExists) {
                $listing = app(DaemonFileRepository::class)
                    ->setServer($server)
                    ->getDirectory('/mods');
                $fileExists = collect($listing)
                    ->where('file', true)
                    ->contains('name', $fileName);
            }
        } catch (\Exception $e) {
            throw new DisplayException('Failed to verify file existence: '.$e->getMessage());
        }

        if (! $fileExists) {
            throw new DisplayException('The jar file no longer exists in /mods.');
        }

        $this->scanJar($server, $fileName);

        $mod = $server->mods()->create([
            'provider' => 'manual',
            'project_id' => 'manual:'.$fileName,
            'slug' => $request->input('slug'),
            'title' => $request->input('title'),
            'version_id' => $request->input('version'),
            'version_number' => $request->input('version'),
            'file_name' => $fileName,
            'icon_url' => null,
        ]);

        Cache::forget(sprintf('server:%d:mods-dir', $server->id));

        Activity::event('server:mod.install')
            ->property('mod', $mod->title.' '.$mod->version_number.' (manual upload)')
            ->log();

        return $this->fractal->item($mod)
            ->transformWith($this->getTransformer(ServerModTransformer::class))
            ->toArray();
    }

    private function scanJar(Server $server, string $fileName): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'avscan_');
        try {
            app(DaemonFileRepository::class)
                ->setServer($server)
                ->streamContentToFile('/mods/'.$fileName, $tmp, 64 * 1024 * 1024);
            $scan = app(FileScanService::class)->scan($tmp);
            if ($scan->isInfected()) {
                throw new DisplayException("Jar file failed virus scan: {$scan->getSignature()}");
            }
            if ($scan->isError() && config('panel.file_scan.strict')) {
                throw new DisplayException('File scanner error: '.$scan->getMessage());
            }
        } catch (\Throwable $e) {
            @unlink($tmp);

            throw $e;
        }
        @unlink($tmp);
    }

    public function store(InstallModRequest $request, Server $server): array|JsonResponse
    {
        $this->assertEnabled($server);
        if (! $request->boolean('replace')) {
            $duplicate = $this->manager->crossProviderDuplicate(
                $server,
                $request->input('provider'),
                $request->input('slug') ?? $request->input('title') ?? $request->input('project_id'),
            );

            if ($duplicate) {
                return new JsonResponse([
                    'errors' => [[
                        'code' => 'CrossProviderDuplicate',
                        'status' => '409',
                        'detail' => sprintf('"%s" is already installed from %s.', $duplicate->title, $duplicate->provider),
                        'meta' => [
                            'provider' => $duplicate->provider,
                            'title' => $duplicate->title,
                        ],
                    ]],
                ], 409);
            }
        }

        try {
            $mod = $this->manager->install(
                $server,
                $request->input('provider'),
                $request->input('project_id'),
                $request->input('title'),
                $request->input('icon_url'),
                $request->input('version_id'),
                $request->input('slug'),
            );
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to install mod: '.$e->getMessage());
        }

        Activity::event('server:mod.install')
            ->property('mod', $mod->title.' '.$mod->version_number)
            ->log();

        return $this->fractal->item($mod)
            ->transformWith($this->getTransformer(ServerModTransformer::class))
            ->toArray();
    }

    public function update(UpdateModRequest $request, Server $server, int $mod): array
    {
        $this->assertEnabled($server);
        $mod = $this->findMod($server, $mod);

        try {
            $mod = $this->manager->update($server, $mod);
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to update mod: '.$e->getMessage());
        }

        Activity::event('server:mod.update')
            ->property('mod', $mod->title.' '.$mod->version_number)
            ->log();

        return $this->fractal->item($mod)
            ->transformWith($this->getTransformer(ServerModTransformer::class))
            ->toArray();
    }

    /**
     * Link a manual mod to a provider for updates.
     */
    public function link(InstallModRequest $request, Server $server, int $mod): array
    {
        $this->assertEnabled($server);
        $mod = $this->findMod($server, $mod);

        if ($mod->provider !== 'manual') {
            throw new DisplayException('Only manual mods can be linked to a provider.');
        }

        $mod->update([
            'provider' => $request->input('provider'),
            'project_id' => $request->input('project_id'),
            'slug' => $request->input('slug') ?? $mod->slug,
            'title' => $request->input('title') ?? $mod->title,
            'version_id' => $request->input('version_id'),
            'version_number' => $request->input('version_number'),
            'icon_url' => $request->input('icon_url'),
        ]);

        Activity::event('server:mod.link')
            ->property('mod', $mod->title.' linked to '.$request->input('provider'))
            ->log();

        return $this->fractal->item($mod->refresh())
            ->transformWith($this->getTransformer(ServerModTransformer::class))
            ->toArray();
    }

    public function toggle(ToggleModRequest $request, Server $server, int $mod): array
    {
        $this->assertEnabled($server);
        $mod = $this->manager->toggle($server, $this->findMod($server, $mod));

        Activity::event('server:mod.toggle')
            ->property('mod', $mod->title)
            ->log();

        return $this->fractal->item($mod)
            ->transformWith($this->getTransformer(ServerModTransformer::class))
            ->toArray();
    }

    public function destroy(DeleteModRequest $request, Server $server, int $mod): JsonResponse
    {
        $this->assertEnabled($server);
        $mod = $this->findMod($server, $mod);
        $title = $mod->title;

        $this->manager->delete($server, $mod);

        Activity::event('server:mod.delete')->property('mod', $title)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function bulkUpdate(BulkUpdateModsRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $modIds = $request->input('mod_ids');
        $mods = $server->mods()->whereIn('id', $modIds)->get();

        $results = ['success' => [], 'failed' => []];

        foreach ($mods as $mod) {
            try {
                $updated = $this->manager->update($server, $mod);
                $results['success'][] = [
                    'id' => $updated->id,
                    'title' => $updated->title,
                    'version' => $updated->version_number,
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $mod->id,
                    'title' => $mod->title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($results['success']) > 0) {
            Activity::event('server:mod.bulk-update')
                ->property('count', count($results['success']))
                ->log();
        }

        return new JsonResponse($results);
    }

    public function bulkDestroy(BulkDeleteModsRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $modIds = $request->input('mod_ids');
        $mods = $server->mods()->whereIn('id', $modIds)->get();

        $results = ['success' => [], 'failed' => []];

        foreach ($mods as $mod) {
            try {
                $title = $mod->title;
                $this->manager->delete($server, $mod);
                $results['success'][] = ['id' => $mod->id, 'title' => $title];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $mod->id,
                    'title' => $mod->title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($results['success']) > 0) {
            Activity::event('server:mod.bulk-delete')
                ->property('count', count($results['success']))
                ->log();
        }

        return new JsonResponse($results);
    }

    private function assertEnabled(Server $server): void
    {
        if (! $this->manager->isEnabledFor($server)) {
            throw new DisplayException('Mods are not available for this server.');
        }
    }

    private function findMod(Server $server, int $mod): ServerMod
    {
        return $server->mods()->findOrFail($mod);
    }
}
