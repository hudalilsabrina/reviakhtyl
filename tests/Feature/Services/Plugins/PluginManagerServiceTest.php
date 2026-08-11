<?php

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Plugins\PluginUpToDateException;
use App\Models\Server;
use App\Models\ServerPlugin;
use App\Repositories\Agent\DaemonFileRepository;
use App\Services\Plugins\HangarService;
use App\Services\Plugins\ModrinthService;
use App\Services\Plugins\PluginManagerService;
use App\Services\Plugins\SpigetService;
use App\Services\Security\FileScanService;
use Illuminate\Support\Facades\Cache;

afterEach(function () {
    Mockery::close();
});

function pluginManagerService(array $settings = []): PluginManagerService
{
    $settingsRepo = Mockery::mock(SettingsRepositoryInterface::class);
    $settingsRepo->shouldReceive('get')
        ->andReturnUsing(fn (string $key, mixed $default = null) => $settings[$key] ?? $default);

    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $fileScan = Mockery::mock(FileScanService::class);
    $modrinth = Mockery::mock(ModrinthService::class);
    $hangar = Mockery::mock(HangarService::class);
    $spiget = Mockery::mock(SpigetService::class);

    return new PluginManagerService($fileRepository, $settingsRepo, $fileScan, $modrinth, $hangar, $spiget);
}

function pluginServer(array $plugins = []): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->egg_id = 3;
    $server->plugins = collect($plugins);

    return $server;
}

function makePlugin(array $overrides = []): ServerPlugin
{
    $plugin = Mockery::mock(ServerPlugin::class)->makePartial();
    $plugin->provider = 'modrinth';
    $plugin->project_id = 'vault';
    $plugin->slug = 'vault';
    $plugin->title = 'Vault';
    $plugin->version_id = 'v1';
    $plugin->version_number = '1.0.0';
    $plugin->file_name = 'vault-1.0.0.jar';
    $plugin->icon_url = null;
    $plugin->id = 1;
    foreach ($overrides as $key => $value) {
        $plugin->{$key} = $value;
    }

    return $plugin;
}

beforeEach(function () {
    Cache::flush();
});

it('is enabled only for eggs in the allowlist setting', function () {
    $service = pluginManagerService(['settings::panel:plugins:egg_ids' => [3, 7]]);

    expect($service->isEnabledFor(pluginServer()))->toBeTrue();
    $server = pluginServer();
    $server->egg_id = 9;
    expect($service->isEnabledFor($server))->toBeFalse();
});

it('treats missing or malformed egg_ids as an empty allowlist', function () {
    $service = pluginManagerService();

    expect($service->enabledEggIds())->toBe([]);

    $service = pluginManagerService(['settings::panel:plugins:egg_ids' => 'not-json']);
    expect($service->enabledEggIds())->toBe([]);
});

it('resolves the loader list from BUILD_TYPE', function () {
    $service = pluginManagerService();
    $server = Mockery::mock(Server::class)->makePartial();
    $server->variables = collect([
        (object) ['env_variable' => 'BUILD_TYPE', 'server_value' => 'PAPER'],
        (object) ['env_variable' => 'MINECRAFT_VERSION', 'server_value' => '1.20.1'],
    ]);

    expect($service->loaders($server))->toBe(['paper', 'spigot', 'bukkit'])
        ->and($service->gameVersion($server))->toBe('1.20.1');
});

it('ignores latest and empty game versions', function () {
    $service = pluginManagerService();

    $server = Mockery::mock(Server::class)->makePartial();
    $server->variables = collect([
        (object) ['env_variable' => 'MINECRAFT_VERSION', 'server_value' => 'latest'],
    ]);
    expect($service->gameVersion($server))->toBeNull();

    $server->variables = collect([
        (object) ['env_variable' => 'MINECRAFT_VERSION', 'server_value' => null, 'default_value' => '1.20.1'],
    ]);
    // server_value null falls back to default_value.
    expect($service->gameVersion($server))->toBe('1.20.1');
});

it('detects a duplicate already installed from another provider by slug', function () {
    $service = pluginManagerService();
    $existing = makePlugin(['provider' => 'hangar', 'slug' => 'Vault', 'title' => 'Vault']);
    $server = pluginServer([$existing]);

    $duplicate = $service->crossProviderDuplicate($server, 'modrinth', 'vault');

    expect($duplicate)->toBe($existing);
});

it('does not flag the same provider as a duplicate', function () {
    $service = pluginManagerService();
    $existing = makePlugin(['provider' => 'modrinth', 'slug' => 'vault']);
    $server = pluginServer([$existing]);

    expect($service->crossProviderDuplicate($server, 'modrinth', 'vault'))->toBeNull();
});

it('ignores provider name case when resolving the registry provider', function () {
    $service = pluginManagerService();

    expect($service->provider('modrinth'))->toBeInstanceOf(ModrinthService::class);
});

it('throws DisplayException for an unknown provider', function () {
    $service = pluginManagerService();

    $service->provider('unknown');
})->throws(DisplayException::class, 'Unknown plugin provider.');

it('throws PluginUpToDateException when the latest version matches the current one', function () {
    $service = pluginManagerService();
    $plugin = makePlugin(['version_id' => 'same-id']);

    $modrinth = Mockery::mock(ModrinthService::class);
    $modrinth->shouldReceive('latestVersion')->andReturnNull();

    // Rebuild the service with a provider mock so update() hits the up-to-date path.
    $fileRepository = Mockery::mock(DaemonFileRepository::class);
    $settingsRepo = Mockery::mock(SettingsRepositoryInterface::class);
    $settingsRepo->shouldReceive('get')->andReturnNull();
    $fileScan = Mockery::mock(FileScanService::class);
    $hangar = Mockery::mock(HangarService::class);
    $spiget = Mockery::mock(SpigetService::class);
    $service = new PluginManagerService($fileRepository, $settingsRepo, $fileScan, $modrinth, $hangar, $spiget);

    $server = Mockery::mock(Server::class)->makePartial();
    $server->egg_id = 3;
    $server->plugins = collect([]);
    $server->variables = collect([]);
    $server->id = 3;

    $service->update($server, $plugin);
})->throws(PluginUpToDateException::class);
