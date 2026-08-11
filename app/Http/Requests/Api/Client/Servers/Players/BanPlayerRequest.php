<?php

namespace App\Http\Requests\Api\Client\Servers\Players;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class BanPlayerRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLAYER_MANAGE;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'regex:/^[A-Za-z0-9_]{1,16}$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
