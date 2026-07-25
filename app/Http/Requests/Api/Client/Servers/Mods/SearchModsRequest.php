<?php

namespace App\Http\Requests\Api\Client\Servers\Mods;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class SearchModsRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_MOD_MANAGE;
    }

    public function rules(): array
    {
        return [
            'provider' => ['sometimes', 'nullable', 'string', 'in:modrinth'],
            'query' => ['sometimes', 'nullable', 'string', 'max:128'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'sort' => ['sometimes', 'nullable', 'string', 'in:relevance,downloads,updated'],
        ];
    }
}
