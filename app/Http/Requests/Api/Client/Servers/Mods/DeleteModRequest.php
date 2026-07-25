<?php

namespace App\Http\Requests\Api\Client\Servers\Mods;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class DeleteModRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_MOD_MANAGE;
    }
}
