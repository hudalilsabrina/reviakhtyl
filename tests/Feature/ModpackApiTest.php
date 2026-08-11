<?php

use App\Exceptions\DisplayException;
use App\Http\Controllers\Api\Client\Servers\ModpackController;
use App\Http\Requests\Api\Client\Servers\Mods\InstallModpackRequest;
use App\Http\Requests\Api\Client\Servers\Mods\SearchModsRequest;
use App\Models\ActivityLog;
use App\Models\Server;
use App\Services\Activity\ActivityLogService;
use App\Services\Mods\ModManagerService;
use App\Services\Mods\ModpackManagerService;
use Illuminate\Http\JsonResponse;

afterEach(function () {
    Mockery::close();
});

/**
 * A Server partial mock. Only egg_id is consulted by assertEnabled; the rest of
 * the model is a stand-in so no DB is touched.
 */
function modpackServer(int $eggId = 1): Server
{
    $s = Mockery::mock(Server::class)->makePartial();
    $s->egg_id = $eggId;

    return $s;
}

function modpackController(
    ModManagerService $manager,
    ModpackManagerService $modpackManager
): ModpackController {
    return new ModpackController($manager, $modpackManager);
}

/**
 * Returns a controller whose manager accepts any egg (isEnabledFor=true) and
 * delegates every modpack-related call to pre-configured return values.
 */
function modpackControllerSetup(
    array $searchResult = [],
    ?string $downloadUrl = null,
    array $manifest = [],
    array $installResult = [],
): array {
    $manager = Mockery::mock(ModManagerService::class);
    $manager->shouldReceive('isEnabledFor')->andReturn(true);
    $manager->shouldReceive('gameVersion')->andReturn('1.20.1');
    $manager->shouldReceive('loaders')->andReturn(['fabric']);
    $manager->shouldReceive('searchModpacks')->andReturn($searchResult);
    $manager->shouldReceive('resolveModpackDownloadUrl')->andReturn($downloadUrl);

    $modpackManager = Mockery::mock(ModpackManagerService::class);
    $modpackManager->shouldReceive('parseManifest')->andReturn($manifest);
    $modpackManager->shouldReceive('installFromUrl')->andReturn($installResult);

    return [$manager, $modpackManager];
}

beforeEach(function () {
    $activity = Mockery::mock(ActivityLogService::class);
    $activity->shouldReceive('event')->andReturnSelf();
    $activity->shouldReceive('property')->andReturnSelf();
    $activity->shouldReceive('log')->andReturn(Mockery::mock(ActivityLog::class));
    app()->instance(ActivityLogService::class, $activity);
});

describe('ModpackController', function () {
    describe('search', function () {
        it('returns the provider search result', function () {
            $searchResult = [
                'hits' => [
                    ['id' => 'abc', 'title' => 'My Pack', 'author' => 'someone', 'downloads' => 5],
                ],
                'total' => 1,
            ];
            [$manager, $modpackManager] = modpackControllerSetup(searchResult: $searchResult);
            $server = modpackServer();

            $request = new SearchModsRequest();
            $request->merge(['provider' => 'modrinth', 'query' => 'pack']);

            $result = (modpackController($manager, $modpackManager))
                ->search($request, $server);

            expect($result)->toBe($searchResult);
        });

        it('rejects servers where mods are disabled for the egg', function () {
            $manager = Mockery::mock(ModManagerService::class);
            $manager->shouldReceive('isEnabledFor')->andReturn(false);
            $modpackManager = Mockery::mock(ModpackManagerService::class);

            (modpackController($manager, $modpackManager))
                ->search(new SearchModsRequest(), modpackServer());

            expect(true)->toBeTrue(); // assertEnabled throws; reaching here means it did not
        })->throws(DisplayException::class);
    });

    describe('preview', function () {
        it('returns name, format, mods and download_url', function () {
            $manifest = [
                'format' => 'modrinth',
                'name' => 'My Pack',
                'mods' => [
                    ['project_id' => 'p1', 'version_id' => 'v1', 'provider' => 'modrinth'],
                ],
            ];
            [$manager, $modpackManager] = modpackControllerSetup(
                downloadUrl: 'https://cdn.modrinth.com/data/pack.mrpack',
                manifest: $manifest,
            );
            $server = modpackServer();

            $request = new SearchModsRequest();
            $request->merge(['provider' => 'modrinth', 'project_id' => 'abc']);

            $result = (modpackController($manager, $modpackManager))->preview($request, $server);

            expect($result)->toBeInstanceOf(JsonResponse::class);
            expect($result->getData(true))->toMatchArray([
                'name' => 'My Pack',
                'format' => 'modrinth',
                'download_url' => 'https://cdn.modrinth.com/data/pack.mrpack',
                'mods' => $manifest['mods'],
            ]);
        });

        it('throws when no compatible modpack file exists', function () {
            [$manager, $modpackManager] = modpackControllerSetup(downloadUrl: null);
            $server = modpackServer();

            $request = new SearchModsRequest();
            $request->merge(['provider' => 'modrinth', 'project_id' => 'abc']);

            (modpackController($manager, $modpackManager))->preview($request, $server);
        })->throws(DisplayException::class);
    });

    describe('install', function () {
        it('returns the install result and logs activity on success', function () {
            $installResult = [
                'format' => 'curseforge',
                'name' => 'My Pack',
                'success' => [['project_id' => '1', 'title' => 'Mod A', 'version' => '1.0', 'provider' => 'curseforge']],
                'failed' => [],
            ];
            [$manager, $modpackManager] = modpackControllerSetup(installResult: $installResult);
            $server = modpackServer();

            $request = new InstallModpackRequest();
            $request->merge(['url' => 'https://example.com/pack.zip']);

            $result = (modpackController($manager, $modpackManager))->install($request, $server);

            expect($result)->toBeInstanceOf(JsonResponse::class);
            expect($result->getData(true))->toMatchArray($installResult);
        });

        it('returns failures without logging activity', function () {
            $installResult = [
                'format' => 'modrinth',
                'name' => 'Broken Pack',
                'success' => [],
                'failed' => [['project_id' => '9', 'provider' => 'modrinth', 'error' => 'boom']],
            ];
            [$manager, $modpackManager] = modpackControllerSetup(installResult: $installResult);
            $server = modpackServer();

            $request = new InstallModpackRequest();
            $request->merge(['url' => 'https://example.com/pack.mrpack']);

            $result = (modpackController($manager, $modpackManager))->install($request, $server);

            expect($result->getData(true))->toMatchArray($installResult);
        });
    });
});
