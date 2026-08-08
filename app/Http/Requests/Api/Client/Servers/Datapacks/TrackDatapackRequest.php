<?php

namespace App\Http\Requests\Api\Client\Servers\Datapacks;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class TrackDatapackRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_DATAPACK_MANAGE;
    }

    public function rules(): array
    {
        return [
            'file_name' => ['required', 'string', 'max:191', 'regex:/^[^\/\\\\]+\.zip$/i'],
            'title' => ['required', 'string', 'max:191'],
            'slug' => ['required', 'string', 'max:191'],
            'version' => ['required', 'string', 'max:128'],
        ];
    }
}
