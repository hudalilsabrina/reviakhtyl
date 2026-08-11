<?php

namespace App\Services\Allocations;

use App\Exceptions\DisplayException;
use App\Exceptions\Service\Allocation\AutoAllocationNotEnabledException;
use App\Exceptions\Service\Allocation\CidrOutOfRangeException;
use App\Exceptions\Service\Allocation\InvalidPortMappingException;
use App\Exceptions\Service\Allocation\NoAutoAllocationSpaceAvailableException;
use App\Exceptions\Service\Allocation\PortOutOfRangeException;
use App\Exceptions\Service\Allocation\TooManyPortsInRangeException;
use App\Models\Allocation;
use App\Models\Server;
use Webmozart\Assert\Assert;

class FindAssignableAllocationService
{
    /**
     * FindAssignableAllocationService constructor.
     */
    public function __construct(private AssignmentService $service) {}

    /**
     * Finds an existing unassigned allocation and attempts to assign it to the given server. If
     * no allocation can be found, a new one will be created with a random port between the defined
     * range from the configuration.
     *
     * @throws DisplayException
     * @throws CidrOutOfRangeException
     * @throws InvalidPortMappingException
     * @throws PortOutOfRangeException
     * @throws TooManyPortsInRangeException
     */
    public function handle(Server $server): Allocation
    {
        if (! config('panel.client_features.allocations.enabled')) {
            throw new AutoAllocationNotEnabledException();
        }

        // Attempt to find a given available allocation for a server. If one cannot be found
        // we will fall back to attempting to create a new allocation that can be used for the
        // server.
        //
        // The row lock (requires an enclosing DB transaction, see NetworkAllocationController)
        // makes the find-then-assign step atomic so two concurrent requests cannot claim the
        // same allocation for two different servers.
        /** @var Allocation|null $allocation */
        $allocation = $server->node->allocations()
            ->lockForUpdate()
            ->where('ip', $server->allocation->ip)
            ->whereNull('server_id')
            ->inRandomOrder()
            ->first();

        $allocation = $allocation ?? $this->createNewAllocation($server);

        $allocation->forceFill(['server_id' => $server->id])->save();

        return $allocation->refresh();
    }

    /**
     * Create a new allocation on the server's node with a random port from the defined range
     * in the settings. If there are no matches in that range, or something is wrong with the
     * range information provided an exception will be raised.
     *
     * @throws DisplayException
     * @throws CidrOutOfRangeException
     * @throws InvalidPortMappingException
     * @throws PortOutOfRangeException
     * @throws TooManyPortsInRangeException
     */
    protected function createNewAllocation(Server $server): Allocation
    {
        $start = config('panel.client_features.allocations.range_start', null);
        $end = config('panel.client_features.allocations.range_end', null);

        if (! $start || ! $end) {
            throw new NoAutoAllocationSpaceAvailableException();
        }

        Assert::integerish($start);
        Assert::integerish($end);

        // Lock the node's allocation rows so two concurrent auto-allocation requests cannot
        // both compute the same free port and try to assign it to two different servers.
        // Without this a pair of servers can end up sharing one allocation, and the "random"
        // port picked below may be silently lost to an insertIgnore collision.
        //
        // The primary allocation is locked as part of the set so the whole fast-path/create
        // sequence contends on a stable, single row group; see NetworkAllocationController
        // for the enclosing transaction.
        $lockedIp = $server->node->allocations()
            ->lockForUpdate()
            ->whereKey($server->allocation_id)
            ->value('ip') ?? $server->allocation->ip;

        // Get all of the currently allocated ports for the node so that we can figure out
        // which port might be available.
        $ports = $server->node->allocations()
            ->where('ip', $lockedIp)
            ->whereBetween('port', [$start, $end])
            ->pluck('port');

        // Compute the difference of the range and the currently created ports, finding
        // any port that does not already exist in the database. We will then use this
        // array of ports to create a new allocation to assign to the server.
        $available = array_diff(range($start, $end), $ports->toArray());

        // If we've already allocated all of the ports, just abort.
        if (empty($available)) {
            throw new NoAutoAllocationSpaceAvailableException();
        }

        // Pick a random port out of the remaining available ports.
        /** @var int $port */
        $port = $available[array_rand($available)];

        $this->service->handle($server->node, [
            'allocation_ip' => $lockedIp,
            'allocation_ports' => [$port],
        ]);

        /** @var Allocation $allocation */
        $allocation = $server->node->allocations()
            ->where('ip', $lockedIp)
            ->where('port', $port)
            ->firstOrFail();

        return $allocation;
    }
}
