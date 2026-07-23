<?php

namespace App\Http\Controllers\Api\Client\Servers;

use App\Exceptions\DisplayException;
use App\Facades\Activity;
use App\Http\Controllers\Api\Client\ClientApiController;
use App\Http\Requests\Api\Client\Servers\GetServerRequest;
use App\Models\Server;
use App\Services\Servers\ServerSplitService;
use Illuminate\Http\JsonResponse;

class SplitMergeController extends ClientApiController
{
    public function __construct(private ServerSplitService $splitService)
    {
        parent::__construct();
    }

    public function store(GetServerRequest $request, Server $server, Server $child): JsonResponse
    {
        if (! $request->user()->can('*', $server)) {
            abort(403);
        }

        if ($child->parent_id !== $server->id) {
            throw new DisplayException('The specified server is not a child of this parent.');
        }

        $this->splitService->merge($server, $child);

        Activity::event('server:split.merge')
            ->subject($server, $child)
            ->property(['child_id' => $child->id, 'name' => $child->name])
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }
}
