<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\Egg;
use App\Services\Chatbot\ToolContext;

class ListEggsTool extends AdminTool
{
    public function name(): string
    {
        return 'list_eggs';
    }

    public function description(): string
    {
        return 'List the eggs (server types) available for creating servers, with their id, name, the nest they belong to, and their docker image. Optionally filter by nest_id. Use the returned egg_id in create_server.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'nest_id' => [
                    'type' => 'integer',
                    'description' => 'Optional nest id to narrow the list to eggs inside one nest.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['nest_id' => 'nullable|integer|exists:nests,id'];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $query = Egg::query()->with('nest');

        if (! empty($arguments['nest_id'])) {
            $query->where('nest_id', $arguments['nest_id']);
        }

        $eggs = $query->orderBy('name')->get();

        return [
            'count' => $eggs->count(),
            'eggs' => $eggs->map(fn (Egg $egg) => [
                'id' => $egg->id,
                'name' => $egg->name,
                'nest_id' => $egg->nest_id,
                'nest' => $egg->nest?->name,
                'docker_image' => $egg->docker_image,
            ])->values()->all(),
        ];
    }
}
