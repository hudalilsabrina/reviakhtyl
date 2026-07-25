<?php

namespace App\Services\Servers;

use App\Exceptions\DisplayException;
use App\Models\Allocation;
use App\Models\Server;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class ServerSplitService
{
    private const MIN_CPU = 1;

    private const MIN_MEMORY = 8;

    private const MIN_DISK = 8;

    public function __construct(
        private ConnectionInterface $connection,
        private ServerCreationService $creationService,
        private ServerDeletionService $deletionService,
        private BuildModificationService $buildModificationService,
        private VariableValidatorService $variableValidatorService,
    ) {}

    /**
     * Split a parent server into a new child server, transferring resources.
     *
     * @throws \Throwable
     * @throws DisplayException
     */
    public function split(Server $parent, array $data): Server
    {
        $cpu = (int) ($data['cpu'] ?? 0);
        $memory = (int) ($data['memory'] ?? 0);
        $disk = (int) ($data['disk'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));

        if ($parent->isSuspended()) {
            throw new DisplayException('Cannot split a suspended server.');
        }

        if (! is_null($parent->transfer)) {
            throw new DisplayException('Cannot split a server that is currently being transferred.');
        }

        if (! $parent->isInstalled()) {
            throw new DisplayException('Cannot split a server that is still installing.');
        }

        if ($parent->isSplitChild()) {
            throw new DisplayException('Cannot split a split child.');
        }

        if (! $parent->canSplit()) {
            throw new DisplayException('This server has reached its split limit.');
        }

        if ($this->isRunning($parent)) {
            throw new DisplayException('Stop the server before splitting it.');
        }

        if ($name === '') {
            throw new DisplayException('A name is required for the new server.');
        }

        if ($cpu < self::MIN_CPU || $memory < self::MIN_MEMORY || $disk < self::MIN_DISK) {
            throw new DisplayException(sprintf(
                'Minimum split size is %d%% CPU, %d MB memory, %d MB disk.',
                self::MIN_CPU,
                self::MIN_MEMORY,
                self::MIN_DISK
            ));
        }

        $environment = $parent->variables
            ->mapWithKeys(fn ($variable): array => [
                $variable->env_variable => $variable->server_value ?? $variable->default_value,
            ])
            ->all();

        // Allow overriding individual egg variables (e.g. server jar, version)
        // while defaulting to the parent's current values.
        $overrides = $data['environment'] ?? [];
        if (is_array($overrides)) {
            foreach ($overrides as $key => $value) {
                $environment[$key] = (string) $value;
            }
        }

        // Validate the final environment against egg variable rules at admin level.
        // This ensures required fields are present, regex patterns match, etc.
        $validated = $this->variableValidatorService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle($parent->egg_id, $environment);

        // Extract validated values back into environment array
        $environment = $validated->mapWithKeys(fn ($item) => [$item->key => $item->value])->all();

        $startup = trim((string) ($data['startup'] ?? '')) ?: $parent->startup;

        $image = trim((string) ($data['image'] ?? '')) ?: $parent->image;
        if (! in_array($image, $parent->egg->docker_images, true)) {
            $image = $parent->image;
        }

        // Child must be fully created (and committed) before the daemon is
        // contacted, so creation happens outside the resource transfer below.
        $child = $this->creationService->handle([
            'name' => $name,
            'owner_id' => $parent->owner_id,
            'node_id' => $parent->node_id,
            'allocation_id' => $this->claimAllocation($parent)->id,
            'nest_id' => $parent->nest_id,
            'egg_id' => $parent->egg_id,
            'startup' => $startup,
            'image' => $image,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'swap' => $parent->swap,
            'io' => $parent->io,
            'threads' => $parent->threads,
            'oom_disabled' => $parent->oom_disabled,
            'database_limit' => 0,
            'allocation_limit' => 0,
            'backup_limit' => 0,
            'parent_id' => $parent->id,
            'environment' => $environment,
        ]);

        try {
            $this->connection->transaction(function () use ($parent, $cpu, $memory, $disk) {
                /** @var object{cpu: int, memory: int, disk: int} $locked */
                $locked = DB::table('servers')->where('id', $parent->id)->lockForUpdate()->first(['cpu', 'memory', 'disk']);

                if ($parent->children()->count() >= $parent->split_limit) {
                    throw new DisplayException('This server has reached its split limit.');
                }

                if ($cpu > $locked->cpu || $memory > $locked->memory || $disk > $locked->disk) {
                    throw new DisplayException('Insufficient resources on parent server for this split.');
                }

                $this->buildModificationService->handle($parent, [
                    'cpu' => $locked->cpu - $cpu,
                    'memory' => $locked->memory - $memory,
                    'disk' => $locked->disk - $disk,
                    'allocation_id' => $parent->allocation_id,
                    'database_limit' => $parent->database_limit,
                    'allocation_limit' => $parent->allocation_limit,
                    'backup_limit' => $parent->backup_limit,
                ]);
            }, 5);
        } catch (\Throwable $exception) {
            $this->deletionService->withForce()->handle($child);

            throw $exception;
        }

        return $child;
    }

    /**
     * Claim a free allocation on the parent's node for a split child.
     *
     * @throws DisplayException
     */
    private function claimAllocation(Server $parent): Allocation
    {
        $allocation = Allocation::query()
            ->where('node_id', $parent->node_id)
            ->whereNull('server_id')
            ->lockForUpdate()
            ->first();

        if (! $allocation) {
            throw new DisplayException('No free allocation available on this node.');
        }

        return $allocation;
    }

    /**
     * Merge a split child back into its parent, returning resources and deleting the child.
     *
     * @throws \Throwable
     * @throws DisplayException
     */
    public function merge(Server $parent, Server $child): void
    {
        if ($child->parent_id !== $parent->id) {
            throw new DisplayException('This server is not a split child of the given parent.');
        }

        if ($child->isSuspended()) {
            throw new DisplayException('Cannot merge a suspended server.');
        }

        if ($this->isRunning($child)) {
            throw new DisplayException('Stop the child server before merging it.');
        }

        if ($parent->node_id !== $child->node_id) {
            throw new DisplayException('Cannot merge: parent and child are on different nodes. Move the child to the parent\'s node first, or delete the child manually.');
        }

        $this->connection->transaction(function () use ($parent, $child) {
            /** @var object{cpu: int, memory: int, disk: int} $locked */
            $locked = DB::table('servers')->where('id', $parent->id)->lockForUpdate()->first(['cpu', 'memory', 'disk']);

            $this->buildModificationService->handle($parent, [
                'cpu' => $locked->cpu + $child->cpu,
                'memory' => $locked->memory + $child->memory,
                'disk' => $locked->disk + $child->disk,
                'allocation_id' => $parent->allocation_id,
                'database_limit' => $parent->database_limit,
                'allocation_limit' => $parent->allocation_limit,
                'backup_limit' => $parent->backup_limit,
            ]);

            $this->deletionService->withForce()->handle($child);
        }, 5);
    }

    /**
     * Determines if the server is currently running according to the resolved
     * (daemon-reported) status. Anything other than a definitive stopped state
     * is treated as running to avoid splitting/merging live containers.
     */
    private function isRunning(Server $server): bool
    {
        return ! in_array($server->getResolvedStatus(), ['offline', 'crashed'], true);
    }
}
