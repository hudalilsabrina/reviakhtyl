<?php

namespace App\Transformers\Api\Client;

use App\Models\Allocation;

class AllocationTransformer extends BaseClientTransformer
{
    /**
     * Return the resource name for the JSONAPI output.
     */
    public function getResourceName(): string
    {
        return 'allocation';
    }

    public function transform(Allocation $model): array
    {
        // The server relation can be null (allocation with server_id = null, e.g. an
        // unassigned port or legacy data), so guard the primary check.
        $isDefault = $model->server !== null && $model->server->allocation_id === $model->id;

        return [
            'id' => $model->id,
            'ip' => $model->ip,
            'ip_alias' => $model->ip_alias,
            'port' => $model->port,
            'notes' => $model->notes,
            'is_default' => $isDefault,
        ];
    }
}
