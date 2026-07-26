<?php

namespace App\Http\Requests\Api\Client\Servers\Properties;

use App\Http\Requests\Api\Client\ClientApiRequest;
use App\Models\Permission;
use App\Services\Properties\ServerPropertiesService;

class UpdateRawPropertiesRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_PROPERTIES_MANAGE;
    }

    public function rules(): array
    {
        return [
            'content' => ['present', 'string', 'max:'.ServerPropertiesService::MAX_BYTES],
        ];
    }
}
