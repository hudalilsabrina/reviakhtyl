<?php

namespace App\Services\Chatbot;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The server and user a tool call is executed on behalf of.
 *
 * Every permission check the chatbot makes flows through here, so the assistant
 * can never do more on a server than the person talking to it could do by hand.
 */
class ToolContext
{
    public function __construct(
        public readonly Server $server,
        public readonly User $user,
    ) {}

    public function can(string $permission): bool
    {
        return Gate::forUser($this->user)->allows($permission, $this->server);
    }

    /**
     * @param  string[]  $permissions
     */
    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
