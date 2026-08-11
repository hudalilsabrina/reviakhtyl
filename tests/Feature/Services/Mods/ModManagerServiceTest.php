<?php

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\Service\Mods\ModUpToDateException;
use App\Models\Server;
use App\Models\ServerMod;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Mods\CurseForgeService;
use App\Services\Mods\ModManagerService;
use App\Services\Mods\ModrinthService;
use App\Services\Security\FileScanService;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function modManagerService(array $settings = []): ModManagerService
{
    $settingsRepo = Mockery::mock(SettingsRepositoryInterface::class);
    $settingsRepo->shouldReceive('get')
        ->andReturnUsing(fn (string $key, mixed $default = null) => $settings[$key] ?? $default);

    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $fileScan = Mockery::mock(FileScanService::class);
    $modrinth = Mockery::mock(ModrinthService::class);
    $curseforge = Mockery::mock(CurseForgeService::class);

    return new ModManagerService($fileRepository, $settingsRepo, $fileScan, $modrinth, $curseforge);
}

function modServer(array $mods = [], array $variables = []): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->egg_id = 3;
    $server->mods = collect($mods);
    $server->variables = collect($variables);
    // loaders() may call loadMissing('egg'); a partial mock would hit the DB.
    // Populate a dummy egg relation so the egg-name fallback never queries.
    $server->shouldReceive('loadMissing')->andReturnUsing(function () use ($server) {
        $server->setRelation('egg', (object) ['name' => '']);

        return $server;
    });

    return $server;
}

function makeMod(array $overrides = []): ServerMod
{
    $mod = Mockery::mock(ServerMod::class)->makePartial();
    $mod->provider = 'modrinth';
    $mod->project_id = 'sodium';
    $mod->slug = 'sodium';
    $mod->title = 'Sodium';
    $mod->version_id = 'v1';
    $mod->version_number = '1.0.0';
    $mod->file_name = 'sodium-1.0.0.jar';
    $mod->icon_url = null;
    $mod->id = 1;
    foreach ($overrides as $key => $value) {
        $mod->{$key} = $value;
    }

    return $mod;
}

beforeEach(function () {
    Cache::flush();
});

it('is enabled only for eggs in the mods allowlist setting', function () {
    $service = modManagerService(['settings::panel:mods:egg_ids' => [3, 7]]);

    expect($service->isEnabledFor(modServer()))->toBeTrue();
    $server = modServer();
    $server->egg_id = 9;
    expect($service->isEnabledFor($server))->toBeFalse();
});

it('detects loaders from MOD_LOADER variable and expands neoforge with forge', function () {
    $service = modManagerService();
    $server = modServer([], [
        (object) ['env_variable' => 'MOD_LOADER', 'server_value' => 'neoforge'],
    ]);

    expect($service->loaders($server))->toBe(['neoforge', 'forge']);
});

it('falls back to BUILD_TYPE when MOD_LOADER is absent', function () {
    $service = modManagerService();
    $server = modServer([], [
        (object) ['env_variable' => 'BUILD_TYPE', 'server_value' => 'fabric'],
    ]);

    expect($service->loaders($server))->toBe(['fabric']);
});

it('defaults to all mod loaders when nothing detects', function () {
    $service = modManagerService();
    $server = modServer([], [
        (object) ['env_variable' => 'BUILD_TYPE', 'server_value' => 'paper'],
    ]);

    expect($service->loaders($server))->toBe(['fabric', 'forge', 'neoforge', 'quilt']);
});

it('reads MINECRAFT_VERSION then MC_VERSION', function () {
    $service = modManagerService();
    $server = modServer([], [
        (object) ['env_variable' => 'MINECRAFT_VERSION', 'server_value' => '1.20.1'],
    ]);
    expect($service->gameVersion($server))->toBe('1.20.1');

    $server = modServer([], [
        (object) ['env_variable' => 'MC_VERSION', 'server_value' => '1.21'],
    ]);
    expect($service->gameVersion($server))->toBe('1.21');
});

it('detects a duplicate installed from another provider by title', function () {
    $service = modManagerService();
    $existing = makeMod(['provider' => 'curseforge', 'slug' => 'sodium', 'title' => 'Sodium']);
    $server = modServer([$existing]);

    $duplicate = $service->crossProviderDuplicate($server, 'modrinth', 'sodium');

    expect($duplicate)->toBe($existing);
});

it('does not flag the same provider as a duplicate', function () {
    $service = modManagerService();
    $existing = makeMod(['provider' => 'modrinth']);
    $server = modServer([$existing]);

    expect($service->crossProviderDuplicate($server, 'modrinth', 'sodium'))->toBeNull();
});

it('throws ModUpToDateException when the latest version matches the current one', function () {
    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $settingsRepo = Mockery::mock(SettingsRepositoryInterface::class);
    $settingsRepo->shouldReceive('get')->andReturnNull();
    $fileScan = Mockery::mock(FileScanService::class);
    $modrinth = Mockery::mock(ModrinthService::class);
    $modrinth->shouldReceive('latestVersion')->andReturnNull();
    $curseforge = Mockery::mock(CurseForgeService::class);

    $service = new ModManagerService($fileRepository, $settingsRepo, $fileScan, $modrinth, $curseforge);

    $server = modServer([], []);
    $server->id = 3;

    $service->update($server, makeMod(['version_id' => 'same-id']));
})->throws(ModUpToDateException::class);
