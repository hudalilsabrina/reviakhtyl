<?php

use App\Exceptions\Service\Allocation\AutoAllocationNotEnabledException;
use App\Exceptions\Service\Allocation\NoAutoAllocationSpaceAvailableException;
use App\Models\Allocation;
use App\Models\Node;
use App\Models\Server;
use App\Services\Allocations\AssignmentService;
use App\Services\Allocations\FindAssignableAllocationService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Builds a FindAssignableAllocationService whose AssignmentService is a stub
 * (nothing real ever touches the database).
 */
function makeAssignableService(): FindAssignableAllocationService
{
    $assignmentService = Mockery::mock(AssignmentService::class);
    $assignmentService->shouldReceive('handle');

    return new FindAssignableAllocationService($assignmentService);
}

/**
 * Builds a partial-mock Allocation that records writes to `server_id` without
 * ever touching a database, and returns itself from `refresh()`.
 */
function makeAssignableAllocation(array $attributes): Allocation
{
    $allocation = Mockery::mock(Allocation::class)->makePartial();
    $allocation->forceFill($attributes);
    $allocation->shouldReceive('save')->andReturn(true);
    $allocation->shouldReceive('refresh')->andReturnSelf();

    return $allocation;
}

/**
 * Builds a Server mock whose `node->allocations()` relation is a mock HasMany
 * that delegates the query-building chain (`lockForUpdate`, `where`, ...) to
 * the test and finally yields the given free allocation from `first()`.
 */
function makeAssignableServer(
    array $allocations = [],
    ?Allocation $first = null,
    ?string $primaryIp = '10.0.0.1',
): Server {
    $node = Mockery::mock(Node::class);
    $node->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $node->shouldReceive('allocations')->andReturn($allocations);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('allocation_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node')->andReturn($node);
    $server->shouldReceive('getAttribute')->with('allocation')->andReturn(
        makeAssignableAllocation([
            'id' => 1,
            'node_id' => 1,
            'ip' => $primaryIp,
            'port' => 25565,
            'server_id' => 1,
        ])
    );

    return $server;
}

/**
 * Chains the query-builder expectations on a HasMany mock so the shared
 * find-an-unassigned-allocation query resolves to `$first`.
 */
function expectFreeAllocationLookup(HasMany $relation, ?Allocation $first): void
{
    $relation->shouldReceive('lockForUpdate')->andReturnSelf();
    $relation->shouldReceive('where')->andReturnSelf();
    $relation->shouldReceive('whereNull')->andReturnSelf();
    $relation->shouldReceive('inRandomOrder')->andReturnSelf();
    $relation->shouldReceive('first')->andReturn($first);
}

beforeEach(function () {
    config(['panel.client_features.allocations.enabled' => true]);
    config(['panel.client_features.allocations.range_start' => 20000]);
    config(['panel.client_features.allocations.range_end' => 20010]);
});

it('throws when auto-allocation is disabled', function () {
    config(['panel.client_features.allocations.enabled' => false]);
    $server = makeAssignableServer();

    expect(fn () => makeAssignableService()->handle($server))
        ->toThrow(AutoAllocationNotEnabledException::class);
});

it('throws when no range is configured and no free allocation exists', function () {
    config(['panel.client_features.allocations.range_start' => null]);
    config(['panel.client_features.allocations.range_end' => null]);

    $relation = Mockery::mock(HasMany::class);
    expectFreeAllocationLookup($relation, null);

    $node = Mockery::mock(Node::class);
    $node->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $node->shouldReceive('allocations')->andReturn($relation);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('allocation_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node')->andReturn($node);
    $server->shouldReceive('getAttribute')->with('allocation')->andReturn(
        makeAssignableAllocation([
            'id' => 1, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 25565, 'server_id' => 1,
        ])
    );

    expect(fn () => makeAssignableService()->handle($server))
        ->toThrow(NoAutoAllocationSpaceAvailableException::class);
});

it('assigns an existing unassigned allocation on the same ip', function () {
    $free = makeAssignableAllocation([
        'id' => 2, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 25566, 'server_id' => null,
    ]);

    $relation = Mockery::mock(HasMany::class);
    expectFreeAllocationLookup($relation, $free);

    $node = Mockery::mock(Node::class);
    $node->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $node->shouldReceive('allocations')->andReturn($relation);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('allocation_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node')->andReturn($node);
    $server->shouldReceive('getAttribute')->with('allocation')->andReturn(
        makeAssignableAllocation([
            'id' => 1, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 25565, 'server_id' => 1,
        ])
    );

    $service = makeAssignableService();
    $result = $service->handle($server);

    expect($result)->toBe($free)
        ->and($free->server_id)->toBe(1);
});

it('creates a new allocation in the configured range when none is free', function () {
    $created = makeAssignableAllocation([
        'id' => 99, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 20005, 'server_id' => null,
    ]);

    $relation = Mockery::mock(HasMany::class);
    expectFreeAllocationLookup($relation, null);

    // The createNewAllocation path: lock the primary allocation, compute free
    // ports, insert via AssignmentService, then look the new row back up.
    $relation->shouldReceive('lockForUpdate')->andReturnSelf();
    $relation->shouldReceive('whereKey')->andReturnSelf();
    $relation->shouldReceive('value')->andReturn('10.0.0.1');
    $relation->shouldReceive('whereBetween')->andReturnSelf();
    $relation->shouldReceive('pluck')->andReturn(new Collection());
    $relation->shouldReceive('firstOrFail')->andReturn($created);

    $node = Mockery::mock(Node::class);
    $node->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $node->shouldReceive('allocations')->andReturn($relation);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('allocation_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node_id')->andReturn(1);
    $server->shouldReceive('getAttribute')->with('node')->andReturn($node);
    $server->shouldReceive('getAttribute')->with('allocation')->andReturn(
        makeAssignableAllocation([
            'id' => 1, 'node_id' => 1, 'ip' => '10.0.0.1', 'port' => 25565, 'server_id' => 1,
        ])
    );

    $service = makeAssignableService();
    $result = $service->handle($server);

    expect($result)->toBe($created)
        ->and($created->server_id)->toBe(1);
});
