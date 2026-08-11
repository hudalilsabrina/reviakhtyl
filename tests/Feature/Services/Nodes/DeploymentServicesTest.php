<?php

use App\Contracts\Repository\AllocationRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Deployment\NoViableAllocationException;
use App\Models\Allocation;
use App\Services\Allocations\AssignmentService;
use App\Services\Deployment\AllocationSelectionService;

function makeAllocationSelectionService(?Allocation $allocation = null): AllocationSelectionService
{
    $repository = Mockery::mock(AllocationRepositoryInterface::class);
    $repository->shouldReceive('getRandomAllocation')
        ->andReturn($allocation);

    return new AllocationSelectionService($repository);
}

it('returns the allocation picked by the repository', function () {
    $allocation = new Allocation();
    $allocation->forceFill(['id' => 42, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 25565, 'server_id' => null]);

    $result = makeAllocationSelectionService($allocation)->handle();

    expect($result->id)->toBe(42);
});

it('throws when no allocation is viable', function () {
    expect(fn () => makeAllocationSelectionService(null)->handle())
        ->toThrow(NoViableAllocationException::class);
});

it('forwards node and port constraints to the repository', function () {
    $repository = Mockery::mock(AllocationRepositoryInterface::class);
    $repository->shouldReceive('getRandomAllocation')
        ->once()
        ->with([1, 2], [[25565, 25570], 30000], true)
        ->andReturnNull();

    $service = new AllocationSelectionService($repository);
    $service->setDedicated(true)
        ->setNodes([1, 2])
        ->setPorts(['25565-25570', '30000']);

    expect(fn () => $service->handle())
        ->toThrow(NoViableAllocationException::class);
});

it('rejects port ranges larger than the limit', function () {
    $service = makeAllocationSelectionService();

    expect(fn () => $service->setPorts(['10000-12000']))
        ->toThrow(DisplayException::class);
});

it('ignores invalid port values in setPorts', function () {
    $repository = Mockery::mock(AllocationRepositoryInterface::class);
    $repository->shouldReceive('getRandomAllocation')
        ->once()
        ->with([], [30000], false)
        ->andReturnNull();

    $service = new AllocationSelectionService($repository);
    $service->setPorts(['not-a-port', '30000', 'bogus-range']);

    expect(fn () => $service->handle())
        ->toThrow(NoViableAllocationException::class);
});

it('exposes the assignment port range limit constant', function () {
    expect(AssignmentService::PORT_RANGE_LIMIT)->toBe(1000)
        ->and(AssignmentService::PORT_RANGE_REGEX)->toBe('/^(\d{4,5})-(\d{4,5})$/');
});
