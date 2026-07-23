<?php

namespace App\Transformers\Api\Client;

use App\Models\Server;

class ServerSplitTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return Server::RESOURCE_NAME;
    }

    public function transform(Server $model): array
    {
        return [
            'id' => $model->id,
            'uuid' => $model->uuid,
            'uuidShort' => $model->uuidShort,
            'name' => $model->name,
            'description' => $model->description,
            'cpu' => $model->cpu,
            'memory' => $model->memory,
            'disk' => $model->disk,
            'parent_id' => $model->parent_id,
            'created_at' => $model->created_at?->toAtomString(),
        ];
    }
}
