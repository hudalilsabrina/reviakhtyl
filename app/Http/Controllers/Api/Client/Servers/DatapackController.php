<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Datapacks\BulkDeleteDatapacksRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\BulkUpdateDatapacksRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\DeleteDatapackRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\InstallDatapackRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\SearchDatapacksRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\ToggleDatapackRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\TrackDatapackRequest;
use App\Http\Requests\Api\Client\Servers\Datapacks\UpdateDatapackRequest;
use App\Models\Server;
use App\Models\ServerDatapack;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Datapacks\DatapackManagerService;
use App\Services\Datapacks\DatapackZipService;
use App\Services\Security\FileScanService;
use App\Transformers\Api\Client\ServerDatapackTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class DatapackController extends ClientApiController
{
    public function __construct(
        private DatapackManagerService $manager,
        private DatapackZipService $zips
    ) {
        parent::__construct();
    }

    public function index(SearchDatapacksRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $this->manager->provider($request->input('provider', 'modrinth'));

        return [
            'datapacks' => $this->fractal->collection($server->datapacks)
                ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
                ->toArray(),
            'game_version' => $this->manager->gameVersion($server),
        ];
    }

    public function search(SearchDatapacksRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $result = $this->manager->provider($request->input('provider', 'modrinth'))->search(
            $request->input('query', '') ?? '',
            $this->manager->gameVersion($server) ? [$this->manager->gameVersion($server)] : [],
            $request->integer('limit', 20),
            $request->integer('offset', 0),
            $request->input('sort', 'relevance') ?? 'relevance',
        );

        $installed = $server->datapacks
            ->mapWithKeys(fn ($d) => [$d->provider.':'.$d->project_id => $d->version_number]);

        return [
            'hits' => array_map(fn ($hit) => $hit + [
                'installed_version' => $installed[$request->input('provider', 'modrinth').':'.$hit['id']] ?? null,
            ], $result['hits']),
            'total' => $result['total'],
        ];
    }

    public function versions(SearchDatapacksRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $request->validate(['project_id' => ['required', 'string', 'max:128']]);

        $provider = $this->manager->provider($request->input('provider', 'modrinth'));
        $versions = $provider->versions(
            $request->input('project_id'),
            $this->manager->gameVersion($server) ? [$this->manager->gameVersion($server)] : [],
        );

        return [
            'versions' => $versions,
            'dependencies' => [],
        ];
    }

    /**
     * ZIPs in /datapacks that are not tracked, with metadata parsed from pack.mcmeta.
     */
    public function untracked(SearchDatapacksRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $untracked = collect($this->zips->untracked($server))
            ->map(fn ($zip) => $zip + $this->zips->parsePackMcmeta($server, $zip['file_name'], $zip['size']))
            ->values()
            ->all();

        return new JsonResponse(['data' => $untracked]);
    }

    /**
     * Register an uploaded ZIP as a tracked "manual" datapack.
     */
    public function register(TrackDatapackRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $fileName = $request->input('file_name');
        $exists = $server->datapacks()->where('provider', 'manual')->where('file_name', $fileName)->exists();

        if ($exists) {
            throw new DisplayException('This file is already tracked.');
        }

        $fileExists = false;
        try {
            $files = $this->zips->untracked($server);
            $fileExists = collect($files)->contains('file_name', $fileName);

            if (! $fileExists) {
                $listing = app(DaemonFileRepository::class)
                    ->setServer($server)
                    ->getDirectory('/datapacks');
                $fileExists = collect($listing)
                    ->where('file', true)
                    ->contains('name', $fileName);
            }
        } catch (\Exception $e) {
            throw new DisplayException('Failed to verify file existence: '.$e->getMessage());
        }

        if (! $fileExists) {
            throw new DisplayException('The zip file no longer exists in /datapacks.');
        }

        $this->assertCleanZipScan($server, '/datapacks/'.$fileName);

        $datapack = $server->datapacks()->create([
            'provider' => 'manual',
            'project_id' => 'manual:'.$fileName,
            'slug' => $request->input('slug') ?? strtolower(pathinfo($fileName, PATHINFO_FILENAME)),
            'title' => $request->input('title') ?? pathinfo($fileName, PATHINFO_FILENAME),
            'version_id' => $request->input('version'),
            'version_number' => $request->input('version') ?? 'unknown',
            'file_name' => $fileName,
            'icon_url' => null,
        ]);

        Cache::forget(sprintf('server:%d:datapacks-dir', $server->id));

        Activity::event('server:datapack.install')
            ->property('datapack', $datapack->title.' '.$datapack->version_number.' (manual upload)')
            ->log();

        return $this->fractal->item($datapack)
            ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
            ->toArray();
    }

    public function store(InstallDatapackRequest $request, Server $server): array|JsonResponse
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
            $datapack = $this->manager->install(
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
            throw new DisplayException('Failed to install datapack: '.$e->getMessage());
        }

        Activity::event('server:datapack.install')
            ->property('datapack', $datapack->title.' '.$datapack->version_number)
            ->log();

        return $this->fractal->item($datapack)
            ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
            ->toArray();
    }

    public function update(UpdateDatapackRequest $request, Server $server, int $datapack): array
    {
        $this->assertEnabled($server);
        $datapack = $this->findDatapack($server, $datapack);

        try {
            $datapack = $this->manager->update($server, $datapack);
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to update datapack: '.$e->getMessage());
        }

        Activity::event('server:datapack.update')
            ->property('datapack', $datapack->title.' '.$datapack->version_number)
            ->log();

        return $this->fractal->item($datapack)
            ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
            ->toArray();
    }

    /**
     * Link a manual datapack to a provider for updates.
     */
    public function link(InstallDatapackRequest $request, Server $server, int $datapack): array
    {
        $this->assertEnabled($server);
        $datapack = $this->findDatapack($server, $datapack);

        if ($datapack->provider !== 'manual') {
            throw new DisplayException('Only manual datapacks can be linked to a provider.');
        }

        $datapack->update([
            'provider' => $request->input('provider'),
            'project_id' => $request->input('project_id'),
            'slug' => $request->input('slug') ?? $datapack->slug,
            'title' => $request->input('title') ?? $datapack->title,
            'version_id' => $request->input('version_id'),
            'version_number' => $request->input('version_number'),
            'icon_url' => $request->input('icon_url'),
        ]);

        Activity::event('server:datapack.link')
            ->property('datapack', $datapack->title.' linked to '.$request->input('provider'))
            ->log();

        return $this->fractal->item($datapack->refresh())
            ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
            ->toArray();
    }

    public function toggle(ToggleDatapackRequest $request, Server $server, int $datapack): array
    {
        $this->assertEnabled($server);
        $datapack = $this->manager->toggle($server, $this->findDatapack($server, $datapack));

        Activity::event('server:datapack.toggle')
            ->property('datapack', $datapack->title)
            ->log();

        return $this->fractal->item($datapack)
            ->transformWith($this->getTransformer(ServerDatapackTransformer::class))
            ->toArray();
    }

    public function destroy(DeleteDatapackRequest $request, Server $server, int $datapack): JsonResponse
    {
        $this->assertEnabled($server);
        $datapack = $this->findDatapack($server, $datapack);
        $title = $datapack->title;

        $this->manager->delete($server, $datapack);

        Activity::event('server:datapack.delete')->property('datapack', $title)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    public function bulkUpdate(BulkUpdateDatapacksRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $datapackIds = $request->input('datapack_ids');
        $datapacks = $server->datapacks()->whereIn('id', $datapackIds)->get();

        $results = ['success' => [], 'failed' => []];

        foreach ($datapacks as $datapack) {
            try {
                $updated = $this->manager->update($server, $datapack);
                $results['success'][] = [
                    'id' => $updated->id,
                    'title' => $updated->title,
                    'version' => $updated->version_number,
                ];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $datapack->id,
                    'title' => $datapack->title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($results['success']) > 0) {
            Activity::event('server:datapack.bulk-update')
                ->property('count', count($results['success']))
                ->log();
        }

        return new JsonResponse($results);
    }

    public function bulkDestroy(BulkDeleteDatapacksRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $datapackIds = $request->input('datapack_ids');
        $datapacks = $server->datapacks()->whereIn('id', $datapackIds)->get();

        $results = ['success' => [], 'failed' => []];

        foreach ($datapacks as $datapack) {
            try {
                $title = $datapack->title;
                $this->manager->delete($server, $datapack);
                $results['success'][] = ['id' => $datapack->id, 'title' => $title];
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'id' => $datapack->id,
                    'title' => $datapack->title,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (count($results['success']) > 0) {
            Activity::event('server:datapack.bulk-delete')
                ->property('count', count($results['success']))
                ->log();
        }

        return new JsonResponse($results);
    }

    private function assertEnabled(Server $server): void
    {
        if (! $this->manager->isEnabledFor($server)) {
            throw new DisplayException('Datapacks are not available for this server.');
        }
    }

    private function findDatapack(Server $server, int $datapack): ServerDatapack
    {
        return $server->datapacks()->findOrFail($datapack);
    }

    private function assertCleanZipScan(Server $server, string $remotePath): void
    {
        $scan = app(FileScanService::class)->scanRemoteFile(app(DaemonFileRepository::class), $server, $remotePath);

        if ($scan->isInfected() || ($scan->isError() && app(FileScanService::class)->isStrict())
            || ($scan->isError() && str_contains((string) $scan->getMessage(), 'Failed to fetch remote file'))) {
            app(DaemonFileRepository::class)->setServer($server)->deleteFiles('/datapacks', [basename($remotePath)]);

            if ($scan->isInfected()) {
                throw new DisplayException("Zip file failed virus scan: {$scan->getSignature()}");
            }

            throw new DisplayException('File scanner error: '.$scan->getMessage());
        }
    }
}
