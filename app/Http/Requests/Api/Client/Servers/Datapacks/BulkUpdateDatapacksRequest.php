<?php

namespace App\Http\Requests\Api\Client\Servers\Datapacks;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class BulkUpdateDatapacksRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_DATAPACK_MANAGE;
    }

    public function rules(): array
    {
        return [
            'datapack_ids' => ['required', 'array', 'min:1', 'max:50'],
            'datapack_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
