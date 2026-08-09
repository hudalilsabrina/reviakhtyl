<?php

namespace App\Services\Chatbot\Tools\Admin;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Server;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Arr;

/**
 * A panel-scope tool: not bound to a single server, and only offered to root
 * administrators. `ToolContext::$server` is null for every admin tool, so the
 * per-server permission gates in ChatbotTool never apply — root admin status is
 * the gate.
 */
abstract class AdminTool extends ChatbotTool
{
    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Admin;
    }

    public function isAvailableFor(ToolContext $context): bool
    {
        return (bool) $context->user->root_admin;
    }

    /**
     * Resolves a server from an admin tool's arguments, which name the target
     * by numeric id, full uuid or the 8-character uuidShort identifier.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function resolveServer(array $arguments): Server
    {
        $value = Arr::get($arguments, 'server_id');

        if ($value === null || $value === '') {
            throw new ChatbotException('The "server_id" argument is required — use list_servers to find it.');
        }

        $server = is_numeric($value)
            ? Server::query()->find((int) $value)
            : Server::query()->where('uuid', $value)->orWhere('uuidShort', $value)->first();

        if (! $server) {
            throw new ChatbotException("No server matches \"$value\".");
        }

        return $server;
    }

    /**
     * The shared schema for a server_id argument: an integer id or the server's
     * short identifier.
     */
    protected function serverIdSchema(): array
    {
        return [
            'type' => 'string',
            'description' => 'The server to act on: its numeric id, full uuid or 8-character identifier. Use list_servers to find it.',
        ];
    }

    /**
     * The shared validation rule for a server_id argument.
     */
    protected function serverIdRule(): array
    {
        return ['server_id' => 'required|string|max:36'];
    }
}
