<?php

namespace App\Http\Requests\Api\Client\Servers\Properties;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;

class UpdatePropertiesRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PROPERTIES_MANAGE;
    }

    public function rules(): array
    {
        return [
            // Per-value validation depends on the property being set, so it is
            // handled by ServerPropertiesService::normalize().
            'properties' => ['required', 'array', 'max:512'],
        ];
    }
}
