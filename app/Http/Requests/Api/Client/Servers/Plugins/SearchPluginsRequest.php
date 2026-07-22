<?php

namespace App\Http\Requests\Api\Client\Servers\Plugins;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class SearchPluginsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLUGIN_MANAGE;
    }

    public function rules(): array
    {
        return [
            'provider' => ['sometimes', 'nullable', 'string', 'in:modrinth,hangar,spiget'],
            'query' => ['sometimes', 'nullable', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:relevance,downloads,updated'],
        ];
    }
}
