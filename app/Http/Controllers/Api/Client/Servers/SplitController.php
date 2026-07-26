<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\GetServerRequest;
use App\Http\Requests\Api\Client\Servers\SplitServerRequest;
use App\Models\Server;
use App\Services\Servers\ServerSplitService;
use App\Transformers\Api\Client\ServerSplitTransformer;
use Illuminate\Http\JsonResponse;

class SplitController extends ClientApiController
{
    public function __construct(private ServerSplitService $splitService)
    {
        parent::__construct();
    }

    public function index(GetServerRequest $request, Server $server): array
    {
        if (! $request->user()->can('*', $server)) {
            abort(403);
        }

        if ($server->isSplitChild()) {
            return [
                'split_limit' => 0,
                'can_split' => false,
                'reason' => 'child',
                'remaining' => ['cpu' => 0, 'memory' => 0, 'disk' => 0],
                'children' => [],
            ];
        }

        $children = $server->children()->get();

        $reason = match (true) {
            $server->split_limit <= 0 => 'limit',
            $children->count() >= $server->split_limit => 'max',
            default => null,
        };

        $childTotal = [
            'cpu' => $children->sum('cpu'),
            'memory' => $children->sum('memory'),
            'disk' => $children->sum('disk'),
        ];

        $originalTotal = [
            'cpu' => $server->cpu + $childTotal['cpu'],
            'memory' => $server->memory + $childTotal['memory'],
            'disk' => $server->disk + $childTotal['disk'],
        ];

        return [
            'split_limit' => $server->split_limit,
            'can_split' => $server->canSplit(),
            'reason' => $reason,
            'remaining' => [
                'cpu' => $server->cpu,
                'memory' => $server->memory,
                'disk' => $server->disk,
            ],
            'total' => $originalTotal,
            'defaults' => [
                'name' => $server->name.' (split)',
                'startup' => $server->startup,
                'image' => $server->image,
                'docker_images' => array_values($server->egg->docker_images),
                'variables' => $server->variables
                    ->where('user_viewable', true)
                    ->map(fn ($variable) => [
                        'env_variable' => $variable->env_variable,
                        'name' => $variable->name,
                        'description' => $variable->description,
                        'value' => $variable->server_value ?? $variable->default_value,
                        'editable' => $variable->user_editable,
                    ])
                    ->values()
                    ->all(),
            ],
            'children' => $children->map(fn (Server $child) => $this->fractal->item($child)
                ->transformWith($this->getTransformer(ServerSplitTransformer::class))
                ->toArray()
            )->all(),
        ];
    }

    public function store(SplitServerRequest $request, Server $server): JsonResponse
    {
        $child = $this->splitService->split($server, $request->validated());

        Activity::event('server:split.create')
            ->subject($server, $child)
            ->property(['child_id' => $child->id, 'name' => $child->name])
            ->log();

        return new JsonResponse(
            $this->fractal->item($child)
                ->transformWith($this->getTransformer(ServerSplitTransformer::class))
                ->toArray(),
            JsonResponse::HTTP_CREATED
        );
    }
}
