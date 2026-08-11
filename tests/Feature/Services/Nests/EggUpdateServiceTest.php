<?php

use App\Contracts\Repository\EggRepositoryInterface;
use App\Exceptions\Service\Egg\NoParentConfigurationFoundException;
use App\Models\Egg;
use App\Services\Eggs\EggUpdateService;

function makeEggUpdateService(array $overrides = []): EggUpdateService
{
    $repo = $overrides['repo'] ?? Mockery::mock(EggRepositoryInterface::class);

    return new EggUpdateService($repo);
}

function makeUpdateEgg(int $nestId = 1): Egg
{
    $egg = Mockery::mock(Egg::class);
    $egg->shouldReceive('getAttribute')->with('nest_id')->andReturn($nestId);
    $egg->shouldReceive('getAttribute')->with('id')->andReturn(7);

    return $egg;
}

it('rejects a config_from egg that is not in the same nest', function () {
    $egg = makeUpdateEgg(nestId: 2);

    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('findCountWhere')
        ->once()
        ->with([['nest_id', '=', 2], ['id', '=', 99]])
        ->andReturn(0);

    $service = makeEggUpdateService(['repo' => $repo]);

    expect(fn () => $service->handle($egg, ['config_from' => 99]))
        ->toThrow(NoParentConfigurationFoundException::class, 'must be a child option');
});

it('ignores the file_denylist when updating', function () {
    $egg = makeUpdateEgg();

    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('withoutFreshModel')->once()->andReturnSelf();
    $repo->shouldReceive('update')->once()->with(7, Mockery::on(function (array $data) {
        return ! array_key_exists('file_denylist', $data)
            && $data['name'] === 'Updated';
    }))->andReturn(true);

    $service = makeEggUpdateService(['repo' => $repo]);

    $service->handle($egg, ['name' => 'Updated', 'file_denylist' => ['evil.txt']]);
});
