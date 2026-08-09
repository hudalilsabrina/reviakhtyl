<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Node;
use App\Services\Chatbot\ToolContext;

class ListNodesTool extends AdminTool
{
    public function name(): string
    {
        return 'list_nodes';
    }

    public function description(): string
    {
        return 'List the nodes (machines) the panel deploys servers to, with their id, name, location, memory and disk capacity and current allocation, and how many servers run on them. Use this to pick a node_id when creating a server.';
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $nodes = Node::query()->with(['location', 'servers'])->get();

        return [
            'count' => $nodes->count(),
            'nodes' => $nodes->map(fn (Node $node) => [
                'id' => $node->id,
                'name' => $node->name,
                'location' => $node->location?->short,
                'memory_mb' => $node->memory,
                'memory_allocated_mb' => $node->servers->sum('memory'),
                'disk_mb' => $node->disk,
                'disk_allocated_mb' => $node->servers->sum('disk'),
                'servers' => $node->servers->count(),
            ])->values()->all(),
        ];
    }
}
