<?php

namespace App\Http\Requests\Api\Client\Servers\Subdomain;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class StoreSubdomainRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_SUBDOMAIN_MANAGE;
    }

    public function rules(): array
    {
        return [
            'subdomain' => ['required', 'string', 'min:1', 'max:63', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]$|^[a-zA-Z0-9]$/'],
            'domain_id' => ['required', 'integer', 'exists:cloudflare_domains,id'],
        ];
    }
}
