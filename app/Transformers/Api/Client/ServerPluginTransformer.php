<?php

namespace App\Transformers\Api\Client;

use App\Models\ServerPlugin;

class ServerPluginTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ServerPlugin::RESOURCE_NAME;
    }

    public function transform(ServerPlugin $plugin): array
    {
        return [
            'id' => $plugin->id,
            'provider' => $plugin->provider,
            'project_id' => $plugin->project_id,
            'slug' => $plugin->slug,
            'title' => $plugin->title,
            'version_id' => $plugin->version_id,
            'version_number' => $plugin->version_number,
            'file_name' => $plugin->file_name,
            'icon_url' => $plugin->icon_url,
            'disabled' => str_ends_with($plugin->file_name, '.disabled'),
        ];
    }
}
