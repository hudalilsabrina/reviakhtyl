<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Allocation;
use App\Services\Chatbot\ToolContext;

class ListAllocationsTool extends AdminTool
{
    public function name(): string
    {
        return 'list_allocations';
    }

    public function description(): string
    {
        return 'List the allocations (ip:port pairs) of a node. By default only unassigned allocations are returned, which is what create_server needs for its allocation_id.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'node_id' => [
                    'type' => 'integer',
                    'description' => 'The node whose allocations to list.',
                ],
                'include_used' => [
                    'type' => 'boolean',
                    'description' => 'Also include allocations already assigned to a server. Default false.',
                ],
            ],
            'required' => ['node_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'node_id' => 'required|integer|exists:nodes,id',
            'include_used' => 'nullable|boolean',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $query = Allocation::query()->where('node_id', $arguments['node_id']);

        if (empty($arguments['include_used'])) {
            $query->whereNull('server_id');
        }

        $allocations = $query->orderBy('ip')->orderBy('port')->limit(100)->get();

        return [
            'count' => $allocations->count(),
            'allocations' => $allocations->map(fn (Allocation $allocation) => [
                'id' => $allocation->id,
                'ip' => $allocation->alias ?? $allocation->ip,
                'port' => $allocation->port,
                'assigned' => $allocation->server_id !== null,
                'notes' => $allocation->notes,
            ])->values()->all(),
        ];
    }
}
