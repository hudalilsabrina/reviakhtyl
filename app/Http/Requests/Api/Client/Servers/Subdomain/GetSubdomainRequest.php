<?php

namespace App\Http\Requests\Api\Client\Servers\Subdomain;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class GetSubdomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_SUBDOMAIN_MANAGE;
    }
}
