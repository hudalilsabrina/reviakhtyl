<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Location;
use App\Services\Chatbot\ToolContext;

class ListLocationsTool extends AdminTool
{
    public function name(): string
    {
        return 'list_locations';
    }

    public function description(): string
    {
        return 'List the locations (datacentres/regions) defined on the panel, with their id, short and long name, and how many nodes each contains.';
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $locations = Location::query()->withCount('nodes')->get();

        return [
            'count' => $locations->count(),
            'locations' => $locations->map(fn (Location $location) => [
                'id' => $location->id,
                'short' => $location->short,
                'long' => $location->long,
                'nodes' => $location->nodes_count,
            ])->values()->all(),
        ];
    }
}
