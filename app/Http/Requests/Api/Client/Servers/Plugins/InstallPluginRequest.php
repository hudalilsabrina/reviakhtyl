<?php

namespace App\Http\Requests\Api\Client\Servers\Plugins;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class InstallPluginRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PLUGIN_MANAGE;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:modrinth,hangar,spiget'],
            'project_id' => ['required', 'string', 'max:128'],
            'title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:191'],
            'icon_url' => ['sometimes', 'nullable', 'string', 'max:512'],
            'version_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'replace' => ['sometimes', 'boolean'],
        ];
    }
}
