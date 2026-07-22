<?php

namespace App\Transformers\Api\Client;

use App\Models\ServerSubdomain;

class ServerSubdomainTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ServerSubdomain::RESOURCE_NAME;
    }

    public function transform(ServerSubdomain $model): array
    {
        return [
            'id' => $model->id,
            'subdomain' => $model->subdomain,
            'domain' => $model->domain,
            'cloudflare_domain_id' => $model->cloudflare_domain_id,
            'fqdn' => $model->getFqdn(),
        ];
    }
}
