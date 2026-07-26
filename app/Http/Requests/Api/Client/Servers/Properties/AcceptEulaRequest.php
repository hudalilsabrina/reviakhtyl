<?php

namespace App\Http\Requests\Api\Client\Servers\Properties;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class AcceptEulaRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PROPERTIES_MANAGE;
    }
}
