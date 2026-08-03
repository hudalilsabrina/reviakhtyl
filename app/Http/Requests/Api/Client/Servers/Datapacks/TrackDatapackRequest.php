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
            'file_name' => ['required', 'string', 'max:191'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
