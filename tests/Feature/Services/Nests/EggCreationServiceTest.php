<?php

use App\Contracts\Repository\EggRepositoryInterface;
use App\Exceptions\Service\Egg\NoParentConfigurationFoundException;
use App\Models\Egg;
use App\Services\Eggs\EggCreationService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

function makeEggCreationService(array $overrides = []): EggCreationService
{
    $config = $overrides['config'] ?? Mockery::mock(ConfigRepository::class);
    $repo = $overrides['repo'] ?? Mockery::mock(EggRepositoryInterface::class);

    return new EggCreationService($config, $repo);
}

it('creates an egg with a uuid and default author', function () {
    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('panel.service.author')->once()->andReturn('admin@example.com');

    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('create')->once()->with(Mockery::on(function (array $data) {
        return $data['uuid'] !== '' && is_string($data['uuid'])
            && $data['author'] === 'admin@example.com'
            && $data['name'] === 'My Egg';
    }), true, true)->andReturn(Mockery::mock(Egg::class));

    $service = makeEggCreationService(['config' => $config, 'repo' => $repo]);

    $service->handle(['nest_id' => 1, 'name' => 'My Egg']);
});

it('rejects a config_from egg that is not in the same nest', function () {
    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('findCountWhere')
        ->once()
        ->with([['nest_id', '=', 1], ['id', '=', 99]])
        ->andReturn(0);

    $service = makeEggCreationService(['repo' => $repo]);

    expect(fn () => $service->handle(['nest_id' => 1, 'name' => 'Child', 'config_from' => 99]))
        ->toThrow(NoParentConfigurationFoundException::class, 'must be a child option');
});

it('accepts a config_from egg in the same nest', function () {
    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('findCountWhere')
        ->once()
        ->with([['nest_id', '=', 1], ['id', '=', 99]])
        ->andReturn(1);
    $repo->shouldReceive('create')->once()->andReturn(Mockery::mock(Egg::class));

    $config = Mockery::mock(ConfigRepository::class);
    $config->shouldReceive('get')->with('panel.service.author')->once()->andReturn('admin@example.com');

    $service = makeEggCreationService(['config' => $config, 'repo' => $repo]);

    $service->handle(['nest_id' => 1, 'name' => 'Child', 'config_from' => 99]);
});
