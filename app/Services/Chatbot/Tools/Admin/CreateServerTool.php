<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Egg;
use App\Models\User;
use App\Services\Chatbot\ToolContext;
use App\Services\Servers\ServerCreationService;
use Illuminate\Support\Arr;

class CreateServerTool extends AdminTool
{
    public function __construct(private ServerCreationService $service) {}

    public function name(): string
    {
        return 'create_server';
    }

    public function description(): string
    {
        return 'Create a new server on the panel and tell the daemon to start installing it. Requires the owning user\'s id (find it with list_users), the egg id (find it with list_nests/list_eggs), the allocation id (find free ones with list_allocations on a node) and the resource limits. The server is created in the installing state; it becomes usable when the install completes.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'The server name.',
                ],
                'owner_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric id of the user who will own the server.',
                ],
                'egg_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric id of the egg the server runs.',
                ],
                'allocation_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric id of the primary allocation (ip:port) the server listens on. Must belong to the node the server is created on.',
                ],
                'node_id' => [
                    'type' => 'integer',
                    'description' => 'Optional. The node id. Inferred from the allocation when omitted.',
                ],
                'memory' => [
                    'type' => 'integer',
                    'description' => 'Memory limit in megabytes.',
                ],
                'disk' => [
                    'type' => 'integer',
                    'description' => 'Disk limit in megabytes.',
                ],
                'swap' => [
                    'type' => 'integer',
                    'description' => 'Swap limit in megabytes. Default 0.',
                ],
                'cpu' => [
                    'type' => 'integer',
                    'description' => 'CPU limit in percent. Default 0 (unlimited).',
                ],
                'io' => [
                    'type' => 'integer',
                    'description' => 'I/O priority (10-1000). Default 500.',
                ],
                'threads' => [
                    'type' => 'string',
                    'description' => 'Optional CPU threads constraint (e.g. "0,1").',
                ],
                'start_on_completion' => [
                    'type' => 'boolean',
                    'description' => 'Start the server automatically once installation completes. Default false.',
                ],
                'database_limit' => [
                    'type' => 'integer',
                    'description' => 'How many databases the owner may create. Default 0.',
                ],
                'allocation_limit' => [
                    'type' => 'integer',
                    'description' => 'How many additional allocations the owner may assign. Default 0.',
                ],
                'backup_limit' => [
                    'type' => 'integer',
                    'description' => 'How many backups the owner may create. Default 0.',
                ],
                'environment' => [
                    'type' => 'object',
                    'description' => 'Optional egg environment variables as an object of env-var-name to value (e.g. {"SERVER_JARFILE": "server.jar"}).',
                ],
            ],
            'required' => ['name', 'owner_id', 'egg_id', 'allocation_id', 'memory', 'disk'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:191',
            'owner_id' => 'required|integer|exists:users,id',
            'egg_id' => 'required|integer|exists:eggs,id',
            'allocation_id' => 'required|integer|exists:allocations,id',
            'node_id' => 'nullable|integer|exists:nodes,id',
            'memory' => 'required|numeric|min:0',
            'disk' => 'required|numeric|min:0',
            'swap' => 'nullable|numeric|min:-1',
            'cpu' => 'nullable|numeric|min:0',
            'io' => 'nullable|integer|between:10,1000',
            'threads' => 'nullable|string|max:191',
            'start_on_completion' => 'nullable|boolean',
            'database_limit' => 'nullable|integer|min:0',
            'allocation_limit' => 'nullable|integer|min:0',
            'backup_limit' => 'nullable|integer|min:0',
            'environment' => 'nullable|array',
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $egg = Arr::get($arguments, 'egg_id');
        $memory = Arr::get($arguments, 'memory');

        return 'Create server "'.($arguments['name'] ?? '').'" (egg '.$egg.', '.$memory.' MB)';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $egg = Egg::query()->findOrFail($arguments['egg_id']);
        $owner = User::query()->findOrFail($arguments['owner_id']);

        $server = $this->service->handle([
            'name' => $arguments['name'],
            'owner_id' => $arguments['owner_id'],
            'egg_id' => $arguments['egg_id'],
            'nest_id' => $egg->nest_id,
            'node_id' => $arguments['node_id'] ?? null,
            'allocation_id' => $arguments['allocation_id'],
            'allocation_additional' => [],
            'memory' => $arguments['memory'],
            'disk' => $arguments['disk'],
            'swap' => $arguments['swap'] ?? 0,
            'cpu' => $arguments['cpu'] ?? 0,
            'io' => $arguments['io'] ?? 500,
            'threads' => $arguments['threads'] ?? null,
            'start_on_completion' => (bool) ($arguments['start_on_completion'] ?? false),
            'database_limit' => $arguments['database_limit'] ?? 0,
            'allocation_limit' => $arguments['allocation_limit'] ?? 0,
            'backup_limit' => $arguments['backup_limit'] ?? 0,
            'environment' => $arguments['environment'] ?? [],
        ]);

        return [
            'id' => $server->id,
            'identifier' => $server->uuidShort,
            'name' => $server->name,
            'owner_email' => $owner->email,
            'message' => "Server \"{$server->name}\" was created (id {$server->id}) and installation has been started on the node. It will become usable once the install finishes.",
        ];
    }
}
