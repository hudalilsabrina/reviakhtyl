<?php

namespace App\Http\Requests\Api\Client\Servers\Mods;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class BulkDeleteModsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_MOD_MANAGE;
    }

    public function rules(): array
    {
        return [
            'mod_ids' => ['required', 'array', 'min:1', 'max:50'],
            'mod_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }
}
