<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Plugins\DeletePluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\InstallPluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\SearchPluginsRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\TogglePluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\UpdatePluginRequest;
use App\Models\Server;
use App\Models\ServerPlugin;
use App\Services\Plugins\PluginManagerService;
use App\Transformers\Api\Client\ServerPluginTransformer;
use Illuminate\Http\JsonResponse;

class PluginController extends ClientApiController
{
    public function __construct(private PluginManagerService $manager)
    {
        parent::__construct();
    }

    public function index(SearchPluginsRequest $request, Server $server): array
    {
        $this->manager->provider($request->input('provider', 'modrinth')); // validates provider

        return [
            'plugins' => $this->fractal->collection($server->plugins)
                ->transformWith($this->getTransformer(ServerPluginTransformer::class))
                ->toArray(),
            'game_version' => $this->manager->gameVersion($server),
            'loaders' => $this->manager->loaders($server),
        ];
    }

    public function search(SearchPluginsRequest $request, Server $server): array
    {
        $result = $this->manager->provider($request->input('provider'))->search(
            $request->input('query', '') ?? '',
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
            $request->integer('limit', 20),
            $request->integer('offset', 0),
        );

        $installed = $server->plugins
            ->mapWithKeys(fn ($p) => [$p->provider.':'.$p->project_id => $p->version_number]);

        return [
            'hits' => array_map(fn ($hit) => $hit + [
                'installed_version' => $installed[$request->input('provider').':'.$hit['id']] ?? null,
            ], $result['hits']),
            'total' => $result['total'],
        ];
    }

    public function store(InstallPluginRequest $request, Server $server): array
    {
        try {
            $plugin = $this->manager->install(
                $server,
                $request->input('provider'),
                $request->input('project_id'),
                $request->input('title'),
                $request->input('icon_url'),
            );
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to install plugin: '.$e->getMessage());
        }

        Activity::event('server:plugin.install')
            ->property('plugin', $plugin->title.' '.$plugin->version_number)
            ->log();

        return $this->fractal->item($plugin)
            ->transformWith($this->getTransformer(ServerPluginTransformer::class))
            ->toArray();
    }

    public function update(UpdatePluginRequest $request, Server $server, int $plugin): array
    {
        $plugin = $this->findPlugin($server, $plugin);

        try {
            $plugin = $this->manager->update($server, $plugin);
        } catch (DisplayException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DisplayException('Failed to update plugin: '.$e->getMessage());
        }

        Activity::event('server:plugin.update')
            ->property('plugin', $plugin->title.' '.$plugin->version_number)
            ->log();

        return $this->fractal->item($plugin)
            ->transformWith($this->getTransformer(ServerPluginTransformer::class))
            ->toArray();
    }

    public function toggle(TogglePluginRequest $request, Server $server, int $plugin): array
    {
        $plugin = $this->manager->toggle($server, $this->findPlugin($server, $plugin));

        Activity::event('server:plugin.toggle')
            ->property('plugin', $plugin->title)
            ->log();

        return $this->fractal->item($plugin)
            ->transformWith($this->getTransformer(ServerPluginTransformer::class))
            ->toArray();
    }

    public function destroy(DeletePluginRequest $request, Server $server, int $plugin): JsonResponse
    {
        $plugin = $this->findPlugin($server, $plugin);
        $title = $plugin->title;

        $this->manager->delete($server, $plugin);

        Activity::event('server:plugin.delete')->property('plugin', $title)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    private function findPlugin(Server $server, int $plugin): ServerPlugin
    {
        return $server->plugins()->findOrFail($plugin);
    }
}
