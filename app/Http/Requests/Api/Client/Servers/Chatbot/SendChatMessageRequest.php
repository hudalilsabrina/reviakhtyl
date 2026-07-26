<?php

namespace App\Http\Requests\Api\Client\Servers\Chatbot;

use App\Http\Requests\Api\Client\ClientApiRequest;

/**
 * Anyone with access to the server may talk to the assistant — what it can
 * actually *do* is decided per tool, from the same subuser permissions.
 */
class SendChatMessageRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'content' => 'required|string|min:1|max:8000',
        ];
    }
}
