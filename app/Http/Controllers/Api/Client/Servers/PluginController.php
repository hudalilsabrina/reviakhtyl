<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\Plugins\DeletePluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\InstallPluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\SearchPluginsRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\TogglePluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\TrackPluginRequest;
use App\Http\Requests\Api\Client\Servers\Plugins\UpdatePluginRequest;
use App\Models\Server;
use App\Models\ServerPlugin;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Plugins\PluginJarService;
use App\Services\Plugins\PluginManagerService;
use App\Transformers\Api\Client\ServerPluginTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PluginController extends ClientApiController
{
    public function __construct(private PluginManagerService $manager, private PluginJarService $jars)
    {
        parent::__construct();
    }

    public function index(SearchPluginsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
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
        $this->assertEnabled($server);
        $result = $this->manager->provider($request->input('provider'))->search(
            $request->input('query', '') ?? '',
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
            $request->integer('limit', 20),
            $request->integer('offset', 0),
            $request->input('sort', 'relevance') ?? 'relevance',
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

    public function versions(SearchPluginsRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $request->validate(['project_id' => ['required', 'string', 'max:128']]);

        $provider = $this->manager->provider($request->input('provider'));
        $versions = $provider->versions(
            $request->input('project_id'),
            $this->manager->loaders($server),
            $this->manager->gameVersion($server),
        );

        // Resolve dependency project ids to display data, marking ones already installed.
        $depIds = collect($versions)->pluck('dependencies')->flatten(1)->pluck('project_id')->unique()->all();
        $depProjects = $provider->projects($depIds);
        $installed = $server->plugins
            ->filter(fn ($p) => $p->provider === $request->input('provider'))
            ->keyBy('project_id');

        $dependencies = [];
        foreach ($depIds as $id) {
            if (! isset($depProjects[$id])) {
                continue;
            }
            // Hangar ids are "owner/slug"; dependency metadata only carries the slug.
            $fullId = $request->input('provider') === 'hangar'
                ? ($depProjects[$id]['id'] ?? $id)
                : $id;
            $existing = $installed->first(fn ($p) => $p->project_id === $fullId || str_ends_with($p->project_id, '/'.$id));
            $dependencies[$id] = $depProjects[$id] + [
                'id' => $fullId,
                'installed' => $existing !== null,
            ];
        }

        return [
            'versions' => $versions,
            'dependencies' => $dependencies,
        ];
    }

    /**
     * Jars in /plugins that are not tracked, with metadata parsed from their descriptors.
     */
    public function untracked(SearchPluginsRequest $request, Server $server): JsonResponse
    {
        $this->assertEnabled($server);
        $untracked = collect($this->jars->untracked($server))
            ->map(fn ($jar) => $jar + $this->jars->metadata($server, $jar['file_name'], $jar['size']))
            ->values()
            ->all();

        return new JsonResponse(['data' => $untracked]);
    }

    /**
     * Register an uploaded jar as a tracked "manual" plugin.
     */
    public function register(TrackPluginRequest $request, Server $server): array
    {
        $this->assertEnabled($server);
        $fileName = $request->input('file_name');
        $exists = $server->plugins()->where('provider', 'manual')->where('file_name', $fileName)->exists();

        if ($exists) {
            throw new DisplayException('This file is already tracked.');
        }

        // Verify the jar file actually exists before creating a DB entry
        $fileExists = false;
        try {
            $files = $this->jars->untracked($server);
            $fileExists = collect($files)->contains('file_name', $fileName);

            // Also check tracked files in case it was uploaded manually
            if (! $fileExists) {
                $listing = app(DaemonFileRepository::class)
                    ->setServer($server)
                    ->getDirectory('/plugins');
                $fileExists = collect($listing)
                    ->where('file', true)
                    ->contains('name', $fileName);
            }
        } catch (\Exception $e) {
            throw new DisplayException('Failed to verify file existence: '.$e->getMessage());
        }

        if (! $fileExists) {
            throw new DisplayException('The jar file no longer exists in /plugins.');
        }

        $plugin = $server->plugins()->create([
            'provider' => 'manual',
            'project_id' => 'manual:'.$fileName,
            'slug' => $request->input('slug'),
            'title' => $request->input('title'),
            'version_id' => $request->input('version'),
            'version_number' => $request->input('version'),
            'file_name' => $fileName,
            'icon_url' => null,
        ]);

        // Clear directory cache after tracking
        Cache::forget(sprintf('server:%d:plugins-dir', $server->id));

        Activity::event('server:plugin.install')
            ->property('plugin', $plugin->title.' '.$plugin->version_number.' (manual upload)')
            ->log();

        return $this->fractal->item($plugin)
            ->transformWith($this->getTransformer(ServerPluginTransformer::class))
            ->toArray();
    }

    public function store(InstallPluginRequest $request, Server $server): array|JsonResponse
    {
        $this->assertEnabled($server);
        // Same slug from another provider → ask the client to confirm replacement.
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
            $plugin = $this->manager->install(
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
        $this->assertEnabled($server);
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

    /**
     * Link a manual plugin to a provider for updates.
     */
    public function link(InstallPluginRequest $request, Server $server, int $plugin): array
    {
        $this->assertEnabled($server);
        $plugin = $this->findPlugin($server, $plugin);

        if ($plugin->provider !== 'manual') {
            throw new DisplayException('Only manual plugins can be linked to a provider.');
        }

        // Convert manual plugin to provider plugin by updating its metadata
        $plugin->update([
            'provider' => $request->input('provider'),
            'project_id' => $request->input('project_id'),
            'slug' => $request->input('slug') ?? $plugin->slug,
            'title' => $request->input('title') ?? $plugin->title,
            'version_id' => $request->input('version_id'),
            'version_number' => $request->input('version_number'),
            'icon_url' => $request->input('icon_url'),
        ]);

        Activity::event('server:plugin.link')
            ->property('plugin', $plugin->title.' linked to '.$request->input('provider'))
            ->log();

        return $this->fractal->item($plugin->refresh())
            ->transformWith($this->getTransformer(ServerPluginTransformer::class))
            ->toArray();
    }

    public function toggle(TogglePluginRequest $request, Server $server, int $plugin): array
    {
        $this->assertEnabled($server);
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
        $this->assertEnabled($server);
        $plugin = $this->findPlugin($server, $plugin);
        $title = $plugin->title;

        $this->manager->delete($server, $plugin);

        Activity::event('server:plugin.delete')->property('plugin', $title)->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    private function assertEnabled(Server $server): void
    {
        if (! $this->manager->isEnabledFor($server)) {
            throw new DisplayException('Plugins are not available for this server.');
        }
    }

    private function findPlugin(Server $server, int $plugin): ServerPlugin
    {
        return $server->plugins()->findOrFail($plugin);
    }
}
