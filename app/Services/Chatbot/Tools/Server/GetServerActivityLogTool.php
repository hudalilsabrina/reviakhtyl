<?php

namespace App\Services\Chatbot\Tools\Server;

use App\Enum\ChatbotToolGroup;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Str;

class GetServerActivityLogTool extends ChatbotTool
{
    /** Property values longer than this are trimmed before the model sees them. */
    private const MAX_PROPERTY_LENGTH = 200;

    public function name(): string
    {
        return 'get_activity_log';
    }

    public function description(): string
    {
        return 'Read this server\'s activity log — who did what and when, newest first: power actions, file edits, subuser changes, startup edits, backups, and so on. Use it to answer "what changed recently?", "who restarted the server?", or to find what happened just before something broke. Optionally filter by event name, e.g. "server:power" or "server:file". This is the panel\'s own audit trail; it does not include the game server\'s console output or logs, which live in the log files.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Server;
    }

    public function permissions(): array
    {
        return [Permission::ACTION_ACTIVITY_READ];
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'event' => [
                    'type' => 'string',
                    'description' => 'Only return events whose name contains this, e.g. "power", "file", "subuser".',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'How many entries to return, newest first. Defaults to 25.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'event' => 'sometimes|nullable|string|max:64',
            'limit' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;
        $limit = (int) ($arguments['limit'] ?? 25);

        $entries = $server->activity()
            ->with('actor')
            // Mirrors ActivityLogController: some events are never surfaced to clients.
            ->whereNotIn('activity_logs.event', ActivityLog::DISABLED_EVENTS)
            ->when(
                filled($arguments['event'] ?? null),
                fn ($query) => $query->where('activity_logs.event', 'like', '%'.$arguments['event'].'%')
            )
            ->orderByDesc('timestamp')
            // Over-fetch a little so admin entries removed below do not shrink the result.
            ->limit($limit * 2)
            ->get();

        if (config('activity.hide_admin_activity')) {
            $visible = $server->subusers()->pluck('user_id')->push($server->owner_id)->all();
            $entries = $entries->reject(
                fn (ActivityLog $log) => $log->actor instanceof User
                    && $log->actor->root_admin
                    && ! in_array($log->actor->id, $visible, true)
            );
        }

        return [
            'entries' => $entries->take($limit)->map(fn (ActivityLog $log) => [
                'event' => $log->event,
                'actor' => $this->actor($log, $server),
                'at' => $log->timestamp->toIso8601String(),
                'via_api' => ! is_null($log->api_key_id),
                'properties' => $this->properties($log),
            ])->values()->all(),
        ];
    }

    /**
     * Who performed the action. Scheduled tasks and daemon-initiated events have
     * no actor, which is worth stating rather than leaving blank.
     */
    private function actor(ActivityLog $log, Server $server): string
    {
        if (! $log->actor instanceof User) {
            return 'system';
        }

        return $log->actor->id === $server->owner_id
            ? $log->actor->email.' (owner)'
            : $log->actor->email;
    }

    /**
     * Event details, with IP addresses dropped and long values trimmed.
     *
     * The panel shows IPs only to the actor themselves; rather than reproduce
     * that rule, they are simply never given to the assistant — it has no use
     * for them, and they are the one field here worth leaking.
     *
     * @return array<string, mixed>
     */
    private function properties(ActivityLog $log): array
    {
        if (! $log->properties || $log->properties->isEmpty()) {
            return [];
        }

        return $log->properties
            ->except(['ip', 'useragent'])
            ->map(fn ($value) => is_string($value) ? Str::limit($value, self::MAX_PROPERTY_LENGTH) : $value)
            ->all();
    }
}
