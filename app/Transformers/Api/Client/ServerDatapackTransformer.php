<?php

namespace App\Transformers\Api\Client;

use App\Models\ServerDatapack;

class ServerDatapackTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ServerDatapack::RESOURCE_NAME;
    }

    public function transform(ServerDatapack $datapack): array
    {
        return [
            'id' => $datapack->id,
            'provider' => $datapack->provider,
            'project_id' => $datapack->project_id,
            'slug' => $datapack->slug,
            'title' => $datapack->title,
            'version_id' => $datapack->version_id,
            'version_number' => $datapack->version_number,
            'file_name' => $datapack->file_name,
            'icon_url' => $datapack->icon_url,
            'disabled' => $datapack->disabled,
        ];
    }
}
