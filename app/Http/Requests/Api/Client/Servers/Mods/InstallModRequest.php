<?php

namespace App\Http\Requests\Api\Client\Servers\Mods;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class InstallModRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_MOD_MANAGE;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:modrinth,curseforge'],
            'project_id' => ['required', 'string', 'max:128'],
            'title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'icon_url' => ['sometimes', 'nullable', 'string', 'max:512'],
            'version_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'version_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }
}
