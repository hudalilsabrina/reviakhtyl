<?php

use App\Contracts\Repository\NestRepositoryInterface;
use App\Contracts\Repository\ServerRepositoryInterface;
use App\Exceptions\Service\HasActiveServersException;
use App\Services\Nests\NestDeletionService;

function makeNestDeletionService(array $overrides = []): NestDeletionService
{
    $servers = $overrides['servers'] ?? Mockery::mock(ServerRepositoryInterface::class);
    $repo = $overrides['repo'] ?? Mockery::mock(NestRepositoryInterface::class);

    return new NestDeletionService($servers, $repo);
}

it('blocks deletion when servers are attached to the nest', function () {
    $servers = Mockery::mock(ServerRepositoryInterface::class);
    $servers->shouldReceive('findCountWhere')->once()->with([['nest_id', '=', 2]])->andReturn(1);

    $service = makeNestDeletionService(['servers' => $servers]);

    expect(fn () => $service->handle(2))
        ->toThrow(HasActiveServersException::class, 'A Nest with active servers attached');
});

it('deletes the nest when it has no servers', function () {
    $servers = Mockery::mock(ServerRepositoryInterface::class);
    $servers->shouldReceive('findCountWhere')->once()->andReturn(0);

    $repo = Mockery::mock(NestRepositoryInterface::class);
    $repo->shouldReceive('delete')->once()->with(2)->andReturn(1);

    $service = makeNestDeletionService(['servers' => $servers, 'repo' => $repo]);

    expect($service->handle(2))->toBe(1);
});
