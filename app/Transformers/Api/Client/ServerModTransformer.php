<?php

namespace App\Transformers\Api\Client;

use App\Models\ServerMod;

class ServerModTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ServerMod::RESOURCE_NAME;
    }

    public function transform(ServerMod $mod): array
    {
        return [
            'id' => $mod->id,
            'provider' => $mod->provider,
            'project_id' => $mod->project_id,
            'slug' => $mod->slug,
            'title' => $mod->title,
            'version_id' => $mod->version_id,
            'version_number' => $mod->version_number,
            'file_name' => $mod->file_name,
            'icon_url' => $mod->icon_url,
            'disabled' => $mod->disabled,
        ];
    }
}
