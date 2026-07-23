<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
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
            throw new DisplayException('This server is a split child and does not have splits.');
        }

        $children = $server->children()->get();

        return [
            'split_limit' => $server->split_limit,
            'can_split' => $server->canSplit(),
            'remaining' => [
                'cpu' => $server->cpu,
                'memory' => $server->memory,
                'disk' => $server->disk,
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
