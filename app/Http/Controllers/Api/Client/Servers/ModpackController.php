<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Mods\InstallModpackRequest;
use App\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use App\Models\Server;
use App\Services\Mods\ModManagerService;
use App\Services\Mods\ModpackManagerService;
use Illuminate\Http\JsonResponse;

class ModpackController extends ClientApiController
{
    public function __construct(
        private ModManagerService $manager,
        private ModpackManagerService $modpackManager,
    ) {
        parent::__construct();
    }

    public function search(SearchModsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);

        return $this->manager->searchModpacks(
            $request->input('provider'),
            $request->input('query', '') ?? '',
            $this->manager->gameVersion($server),
            $request->integer('limit', 20),
            $request->integer('offset', 0),
            $request->input('sort', 'relevance') ?? 'relevance',
        );
    }

    public function preview(SearchModsRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $request->validate(['project_id' => ['required', 'string', 'max:128']]);

        $provider = $request->input('provider');
        $downloadUrl = $this->manager->resolveModpackDownloadUrl(
            $provider,
            $request->input('project_id'),
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
        );

        if (! $downloadUrl) {
            throw new DisplayException('No compatible modpack file found for this server.');
        }

        try {
            $manifest = $this->modpackManager->parseManifest($downloadUrl);
        } catch (\Exception $e) {
            throw new DisplayException('Failed to parse modpack: '.$e->getMessage());
        }

        return new JsonResponse([
            'name' => $manifest['name'],
            'format' => $manifest['format'],
            'mods' => $manifest['mods'],
            'download_url' => $downloadUrl,
        ]);
    }

    public function install(InstallModpackRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);

        try {
            $results = $this->modpackManager->installFromUrl($server, $request->input('url'));
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to install modpack: '.$e->getMessage());
        }

        $count = count($results['success']);

        if ($count > 0) {
            Activity::event('server:mod.modpack-install')
                ->property('count', $count)
                ->property('name', $results['name'])
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
}
