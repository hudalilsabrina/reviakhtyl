<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Server;
use App\Services\Chatbot\ToolContext;

class ListServersTool extends AdminTool
{
    public function name(): string
    {
        return 'list_servers';
    }

    public function description(): string
    {
        return 'List servers on the panel, optionally filtered by a search term matching the name, short identifier or owner email. Returns the server id (the numeric primary key), short identifier, name, status, egg and node. Use the returned ids as the server_id for the other server tools.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional search term matched against server name, short identifier or owner email.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of servers to return. Default 20, maximum 100.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:191',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $query = Server::query()->with(['node', 'egg', 'user']);

        if (! empty($arguments['search'])) {
            $search = $arguments['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('uuidShort', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($user) => $user->where('email', 'like', "%{$search}%"));
            });
        }

        $servers = $query
            ->orderByDesc('id')
            ->limit($arguments['limit'] ?? 20)
            ->get();

        return [
            'count' => $servers->count(),
            'servers' => $servers->map(fn (Server $server) => $this->render($server))->values()->all(),
        ];
    }

    private function render(Server $server): array
    {
        return [
            'id' => $server->id,
            'identifier' => $server->uuidShort,
            'name' => $server->name,
            'status' => $server->status,
            'egg' => $server->egg?->name,
            'node' => $server->node?->name,
            'owner_email' => $server->user?->email,
        ];
    }
}
