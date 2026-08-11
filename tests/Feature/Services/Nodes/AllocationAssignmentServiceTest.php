<?php

use App\Contracts\Repository\AllocationRepositoryInterface;
use App\Exceptions\Service\Allocation\CidrOutOfRangeException;
use App\Exceptions\Service\Allocation\InvalidPortMappingException;
use App\Exceptions\Service\Allocation\PortOutOfRangeException;
use App\Exceptions\Service\Allocation\TooManyPortsInRangeException;
use App\Models\Node;
use App\Services\Allocations\AssignmentService;
use Illuminate\Database\ConnectionInterface;

/**
 * Returns a real AssignmentService wired with a fake repository that records
 * the payloads it is asked to insert, and a transaction stub that runs the
 * closure immediately (the service never hits a real database).
 *
 * @return array{0: AssignmentService, 1: stdClass} the second element holds
 *                                                  the captured insert payloads
 */
function makeAssignmentService(): array
{
    $captured = new stdClass();
    $captured->rows = [];

    $repository = Mockery::mock(AllocationRepositoryInterface::class);
    $repository->shouldReceive('insertIgnore')
        ->andReturnUsing(function (array $rows) use ($captured) {
            $captured->rows[] = $rows;

            return true;
        });

    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback());

    $service = new AssignmentService($repository, $connection);

    return [$service, $captured];
}

function makeAssignmentNode(int $id = 1): Node
{
    $node = new Node();
    $node->forceFill(['id' => $id]);

    return $node;
}

it('rejects CIDR masks outside /25-/32', function () {
    [$service] = makeAssignmentService();

    foreach (['10.0.0.1/24', '10.0.0.1/33', '10.0.0.1/0', '10.0.0.1/abc'] as $ip) {
        expect(fn () => $service->handle(makeAssignmentNode(), [
            'allocation_ip' => $ip,
            'allocation_ports' => ['25565'],
        ]))->toThrow(CidrOutOfRangeException::class);
    }
});

it('accepts boundary CIDR masks /25 and /32', function () {
    [$service] = makeAssignmentService();

    expect(fn () => $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1/25',
        'allocation_ports' => ['25565'],
    ]))->not->toThrow(CidrOutOfRangeException::class);

    expect(fn () => $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1/32',
        'allocation_ports' => ['25565'],
    ]))->not->toThrow(CidrOutOfRangeException::class);
});

it('rejects single ports outside the 1025-65535 range', function () {
    [$service] = makeAssignmentService();

    foreach (['80', '1024', '1023', '65536', '0'] as $port) {
        expect(fn () => $service->handle(makeAssignmentNode(), [
            'allocation_ip' => '10.0.0.1',
            'allocation_ports' => [$port],
        ]))->toThrow(PortOutOfRangeException::class);
    }
});

it('accepts boundary single ports 1025 and 65535', function () {
    [$service, $captured] = makeAssignmentService();

    $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1',
        'allocation_ports' => ['1025', '65535'],
    ]);

    // The service calls insertIgnore once per (ip, port), so merge all calls.
    $rows = array_merge(...$captured->rows);
    $ports = collect($rows)->pluck('port')->sort()->values()->all();

    expect($ports)->toBe([1025, 65535]);
});

it('rejects non-digit and non-range port entries', function () {
    [$service] = makeAssignmentService();

    foreach (['abc', '25a', ' 25565', '25565 '] as $port) {
        expect(fn () => $service->handle(makeAssignmentNode(), [
            'allocation_ip' => '10.0.0.1',
            'allocation_ports' => [$port],
        ]))->toThrow(InvalidPortMappingException::class);
    }
});

it('rejects reversed port ranges', function () {
    [$service] = makeAssignmentService();

    expect(fn () => $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1',
        'allocation_ports' => ['30000-20000'],
    ]))->toThrow(TooManyPortsInRangeException::class);
});

it('rejects ranges spanning more than 1000 ports', function () {
    [$service] = makeAssignmentService();

    expect(fn () => $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1',
        'allocation_ports' => ['20000-21001'],
    ]))->toThrow(TooManyPortsInRangeException::class);
});

it('rejects port ranges that dip below 1025 or above 65535', function () {
    [$service] = makeAssignmentService();

    // Each range must stay under the 1000-port cap so the boundary check is what fires.
    foreach (['1000-1500', '65500-65536'] as $range) {
        expect(fn () => $service->handle(makeAssignmentNode(), [
            'allocation_ip' => '10.0.0.1',
            'allocation_ports' => [$range],
        ]))->toThrow(PortOutOfRangeException::class);
    }
});

it('flattens a port range into individual rows', function () {
    [$service, $captured] = makeAssignmentService();

    $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1',
        'allocation_ports' => ['25565-25567'],
        'allocation_alias' => 'mc.example.com',
    ]);

    $rows = array_merge(...$captured->rows);
    $ports = collect($rows)->pluck('port')->sort()->values()->all();

    expect($ports)->toBe([25565, 25566, 25567])
        ->and($rows[0]['node_id'])->toBe(1)
        ->and($rows[0]['ip'])->toBe('10.0.0.1')
        ->and($rows[0]['ip_alias'])->toBe('mc.example.com')
        ->and($rows[0]['server_id'])->toBeNull();
});

it('expands a CIDR across every address in the subnet', function () {
    [$service, $captured] = makeAssignmentService();

    $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1/30',
        'allocation_ports' => ['25565'],
    ]);

    $ips = collect(array_merge(...$captured->rows))->pluck('ip')->sort()->values()->all();

    expect($ips)->toBe(['10.0.0.0', '10.0.0.1', '10.0.0.2', '10.0.0.3']);
});

it('applies a null ip_alias when none is provided', function () {
    [$service, $captured] = makeAssignmentService();

    $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '10.0.0.1',
        'allocation_ports' => ['25565'],
    ]);

    expect($captured->rows[0][0]['ip_alias'])->toBeNull();
});

it('expands an IPv6 CIDR and applies the alias to every row', function () {
    [$service, $captured] = makeAssignmentService();

    $service->handle(makeAssignmentNode(), [
        'allocation_ip' => '2001:db8::1/126',
        'allocation_ports' => ['25565'],
        'allocation_alias' => 'v6.example.com',
    ]);

    $rows = array_merge(...$captured->rows);

    expect(count($rows))->toBe(4)
        ->and(collect($rows)->pluck('ip_alias')->unique()->all())->toBe(['v6.example.com']);
});
