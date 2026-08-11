<?php

namespace App\Http\Requests\Api\Client\Servers\Players;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class DeletePlayerRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLAYER_MANAGE;
    }

    public function rules(): array
    {
        return [];
    }
}
