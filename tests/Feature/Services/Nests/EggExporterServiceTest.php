<?php

use App\Contracts\Repository\EggRepositoryInterface;
use App\Models\Egg;
use App\Models\EggStartupPart;
use App\Models\EggVariable;
use App\Services\Eggs\Sharing\EggExporterService;
use Illuminate\Support\Collection;

function makeExporterEgg(): Egg
{
    $egg = Mockery::mock(Egg::class);
    $egg->shouldReceive('getAttribute')->with('update_url')->andReturn('https://example.com/update.json');
    $egg->shouldReceive('getAttribute')->with('name')->andReturn('Test Egg');
    $egg->shouldReceive('getAttribute')->with('image')->andReturn('ghcr.io/parkervcp/yolks:java_21');
    $egg->shouldReceive('getAttribute')->with('author')->andReturn('admin@example.com');
    $egg->shouldReceive('getAttribute')->with('description')->andReturn('A test egg');
    $egg->shouldReceive('getAttribute')->with('features')->andReturn(['eula']);
    $egg->shouldReceive('getAttribute')->with('docker_images')->andReturn(['Java 21' => 'ghcr.io/parkervcp/yolks:java_21']);
    $egg->shouldReceive('getAttribute')->with('inherit_file_denylist')->andReturn(['evil.txt', '']);
    $egg->shouldReceive('getAttribute')->with('startup')->andReturn('java -jar {{SERVER_JARFILE}}');
    $egg->shouldReceive('getAttribute')->with('inherit_config_files')->andReturn('{}');
    $egg->shouldReceive('getAttribute')->with('inherit_config_startup')->andReturn('{"done":"Done!"}');
    $egg->shouldReceive('getAttribute')->with('inherit_config_logs')->andReturn('{}');
    $egg->shouldReceive('getAttribute')->with('inherit_config_stop')->andReturn('^C');
    $egg->shouldReceive('getAttribute')->with('copy_script_install')->andReturn('exit 0');
    $egg->shouldReceive('getAttribute')->with('copy_script_container')->andReturn('ghcr.io/parkervcp/installers:alpine');
    $egg->shouldReceive('getAttribute')->with('copy_script_entry')->andReturn('ash');

    $variable = Mockery::mock(EggVariable::class);
    $variable->shouldReceive('toArray')->andReturn([
        'id' => 1,
        'egg_id' => 1,
        'name' => 'Server Jarfile',
        'description' => '',
        'env_variable' => 'SERVER_JARFILE',
        'default_value' => 'server.jar',
        'user_viewable' => true,
        'user_editable' => true,
        'rules' => 'required|string',
        'created_at' => 'now',
        'updated_at' => 'now',
    ]);

    $part = Mockery::mock(EggStartupPart::class);
    $part->shouldReceive('toArray')->andReturn([
        'id' => 1,
        'egg_id' => 1,
        'name' => 'nogui',
        'value' => '--nogui',
        'description' => '',
        'default_enabled' => true,
        'required' => false,
        'sort_order' => 0,
        'group_name' => null,
        'created_at' => 'now',
        'updated_at' => 'now',
    ]);

    $egg->shouldReceive('getAttribute')->with('variables')->andReturn(new Collection([$variable]));
    $egg->shouldReceive('getAttribute')->with('startupParts')->andReturn(new Collection([$part]));

    return $egg;
}

it('exports the egg with current version, variables and startup parts', function () {
    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('getWithExportAttributes')->once()->with(5)->andReturn(makeExporterEgg());

    $service = new EggExporterService($repo);

    $struct = json_decode($service->handle(5), true);

    expect($struct['meta']['version'])->toBe(Egg::EXPORT_VERSION)
        ->and($struct['name'])->toBe('Test Egg')
        ->and($struct['author'])->toBe('admin@example.com')
        ->and($struct['docker_images'])->toBe(['Java 21' => 'ghcr.io/parkervcp/yolks:java_21'])
        ->and($struct['file_denylist'])->toBe(['evil.txt']); // empty values filtered out
});

it('strips ids and timestamps from variables and adds field_type', function () {
    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('getWithExportAttributes')->once()->with(5)->andReturn(makeExporterEgg());

    $service = new EggExporterService($repo);

    $struct = json_decode($service->handle(5), true);

    $variable = $struct['variables'][0];

    expect($variable)
        ->not->toHaveKey('id')
        ->not->toHaveKey('egg_id')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at')
        ->toHaveKey('env_variable', 'SERVER_JARFILE')
        ->toHaveKey('field_type', 'text');
});

it('strips ids and timestamps from startup parts', function () {
    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('getWithExportAttributes')->once()->with(5)->andReturn(makeExporterEgg());

    $service = new EggExporterService($repo);

    $struct = json_decode($service->handle(5), true);

    $part = $struct['startup_parts'][0];

    expect($part)
        ->not->toHaveKey('id')
        ->not->toHaveKey('egg_id')
        ->not->toHaveKey('created_at')
        ->not->toHaveKey('updated_at')
        ->toHaveKey('name', 'nogui')
        ->toHaveKey('value', '--nogui');
});
