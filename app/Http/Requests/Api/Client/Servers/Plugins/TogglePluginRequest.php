<?php

namespace App\Http\Requests\Api\Client\Servers\Plugins;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class TogglePluginRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLUGIN_MANAGE;
    }
}
