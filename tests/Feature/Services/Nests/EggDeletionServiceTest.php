<?php

use App\Contracts\Repository\EggRepositoryInterface;
use App\Contracts\Repository\ServerRepositoryInterface;
use App\Exceptions\Service\Egg\HasChildrenException;
use App\Exceptions\Service\HasActiveServersException;
use App\Services\Eggs\EggDeletionService;

function makeEggDeletionService(array $overrides = []): EggDeletionService
{
    $servers = $overrides['servers'] ?? Mockery::mock(ServerRepositoryInterface::class);
    $repo = $overrides['repo'] ?? Mockery::mock(EggRepositoryInterface::class);

    return new EggDeletionService($servers, $repo);
}

it('blocks deletion when servers are attached to the egg', function () {
    $servers = Mockery::mock(ServerRepositoryInterface::class);
    $servers->shouldReceive('findCountWhere')->once()->with([['egg_id', '=', 3]])->andReturn(2);

    $service = makeEggDeletionService(['servers' => $servers]);

    expect(fn () => $service->handle(3))
        ->toThrow(HasActiveServersException::class, 'cannot be deleted from the Panel');
});

it('blocks deletion when the egg is a config parent for other eggs', function () {
    $servers = Mockery::mock(ServerRepositoryInterface::class);
    $servers->shouldReceive('findCountWhere')->once()->with([['egg_id', '=', 3]])->andReturn(0);

    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('findCountWhere')->once()->with([['config_from', '=', 3]])->andReturn(1);

    $service = makeEggDeletionService(['servers' => $servers, 'repo' => $repo]);

    expect(fn () => $service->handle(3))
        ->toThrow(HasChildrenException::class, 'parent to one or more other Eggs');
});

it('deletes the egg when it has no servers and no children', function () {
    $servers = Mockery::mock(ServerRepositoryInterface::class);
    $servers->shouldReceive('findCountWhere')->once()->andReturn(0);

    $repo = Mockery::mock(EggRepositoryInterface::class);
    $repo->shouldReceive('findCountWhere')->once()->andReturn(0);
    $repo->shouldReceive('delete')->once()->with(3)->andReturn(1);

    $service = makeEggDeletionService(['servers' => $servers, 'repo' => $repo]);

    expect($service->handle(3))->toBe(1);
});
