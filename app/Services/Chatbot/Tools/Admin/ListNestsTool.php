<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Nest;
use App\Services\Chatbot\ToolContext;

class ListNestsTool extends AdminTool
{
    public function name(): string
    {
        return 'list_nests';
    }

    public function description(): string
    {
        return 'List the nests (egg categories) defined on the panel, with their id, name and description. Use list_eggs with a nest_id to find the eggs inside a nest.';
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $nests = Nest::query()->withCount('eggs')->get();

        return [
            'count' => $nests->count(),
            'nests' => $nests->map(fn (Nest $nest) => [
                'id' => $nest->id,
                'name' => $nest->name,
                'description' => $nest->description,
                'eggs' => $nest->eggs_count,
            ])->values()->all(),
        ];
    }
}
