<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Services\Chatbot\ToolContext;
use App\Services\Users\UserCreationService;

class CreateUserTool extends AdminTool
{
    public function __construct(private UserCreationService $service) {}

    public function name(): string
    {
        return 'create_user';
    }

    public function description(): string
    {
        return 'Create a new panel user account. If no password is given, a random one is generated and a reset link is emailed to the address.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'description' => 'The username. Unique on the panel.',
                ],
                'email' => [
                    'type' => 'string',
                    'description' => 'The account email address. Unique on the panel.',
                ],
                'password' => [
                    'type' => 'string',
                    'description' => 'Optional password. A random one is generated and emailed when omitted.',
                ],
                'root_admin' => [
                    'type' => 'boolean',
                    'description' => 'Give the account root administrator access. Default false. Granting this is a significant privilege escalation.',
                ],
            ],
            'required' => ['username', 'email'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'username' => 'required|string|min:3|max:191|unique:users,username',
            'email' => 'required|string|email|max:191|unique:users,email',
            'password' => 'nullable|string|min:8',
            'root_admin' => 'nullable|boolean',
        ];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $admin = ! empty($arguments['root_admin']) ? ', root admin' : '';

        return 'Create user '.($arguments['username'] ?? '').' ('.($arguments['email'] ?? '').')'.$admin;
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $user = $this->service->handle([
            'username' => $arguments['username'],
            'email' => $arguments['email'],
            'password' => $arguments['password'] ?? null,
            'root_admin' => (bool) ($arguments['root_admin'] ?? false),
        ]);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'root_admin' => (bool) $user->root_admin,
            'message' => "User \"{$user->username}\" was created.",
        ];
    }
}
