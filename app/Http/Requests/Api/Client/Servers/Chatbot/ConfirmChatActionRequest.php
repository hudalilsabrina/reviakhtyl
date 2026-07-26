<?php

namespace App\Http\Requests\Api\Client\Servers\Chatbot;

use App\Http\Requests\Api\Client\ClientApiRequest;

class ConfirmChatActionRequest extends ClientApiRequest
{
    public function rules(): array
    {
        return [
            'message_uuid' => 'required|uuid',
            'approved' => 'required|boolean',
        ];
    }
}
