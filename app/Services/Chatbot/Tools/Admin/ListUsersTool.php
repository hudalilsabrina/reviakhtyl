<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Models\User;
use App\Services\Chatbot\ToolContext;

class ListUsersTool extends AdminTool
{
    public function name(): string
    {
        return 'list_users';
    }

    public function description(): string
    {
        return 'List users on the panel, optionally filtered by a search term matching the username or email. Returns the user id, username, email, whether they are a root admin, and how many servers they own. Use the returned ids as owner_id for create_server.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'search' => [
                    'type' => 'string',
                    'description' => 'Optional search term matched against username or email.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of users to return. Default 20, maximum 100.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:191',
            'limit' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $query = User::query();

        if (! empty($arguments['search'])) {
            $search = $arguments['search'];
            $query->where(function ($builder) use ($search) {
                $builder->where('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('id')->limit($arguments['limit'] ?? 20)->get();

        return [
            'count' => $users->count(),
            'users' => $users->map(fn (User $user) => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'root_admin' => (bool) $user->root_admin,
                'servers_owned' => $user->servers_count ?? null,
            ])->values()->all(),
        ];
    }
}
