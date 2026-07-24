<?php

namespace App\Http\Requests\Api\Client\Servers;

use App\Http\Requests\Api\Client\ClientApiRequest;

class SplitServerRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return '*';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:191'],
            'description' => ['sometimes', 'nullable', 'string', 'max:191'],
            'cpu' => ['required', 'integer', 'min:0'],
            'memory' => ['required', 'integer', 'min:0'],
            'disk' => ['required', 'integer', 'min:0'],
            'startup' => ['sometimes', 'nullable', 'string', 'max:191'],
            'image' => ['sometimes', 'nullable', 'string', 'max:191'],
            'environment' => ['sometimes', 'array'],
            'environment.*' => ['nullable', 'string', 'max:191'],
        ];
    }
}
