<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\User;
use App\Services\Chatbot\ToolContext;
use App\Services\Users\UserDeletionService;

class DeleteUserTool extends AdminTool
{
    public function __construct(private UserDeletionService $service) {}

    public function name(): string
    {
        return 'delete_user';
    }

    public function description(): string
    {
        return 'Permanently delete a user account. A user who still owns servers cannot be deleted — move their servers to another owner first. This cannot be undone.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'The numeric id of the user to delete.',
                ],
            ],
            'required' => ['user_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['user_id' => 'required|integer|exists:users,id'];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        return 'Permanently delete user id '.($arguments['user_id'] ?? '');
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $user = User::query()->findOrFail($arguments['user_id']);

        if ($user->is($context->user)) {
            throw new ChatbotException('You cannot delete your own account.');
        }

        $username = $user->username;

        try {
            $this->service->handle($user);
        } catch (\Throwable $e) {
            throw new ChatbotException($e->getMessage());
        }

        return ['message' => "User \"$username\" was permanently deleted."];
    }
}
