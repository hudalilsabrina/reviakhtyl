<?php

namespace App\Services\Chatbot\Tools\Startup;

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\EggStartupPart;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\StartupCommandService;

class UpdateStartupPartsTool extends ChatbotTool
{
    public function __construct(private StartupCommandService $startupCommandService) {}

    public function name(): string
    {
        return 'update_startup_parts';
    }

    public function description(): string
    {
        return 'Switch the optional pieces of a modular startup command on or off, for eggs that support them. Send every part you want to control; any part you leave out falls back to the egg\'s default state rather than keeping its current one, so send the full list from get_startup with your changes applied. Parts the egg marks as required cannot be switched off. The server keeps running with its old command until it is restarted.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Startup;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'part_id' => [
                                'type' => 'integer',
                                'description' => 'The id of the startup part, as returned by get_startup.',
                            ],
                            'enabled' => [
                                'type' => 'boolean',
                                'description' => 'Whether this part should be included in the startup command.',
                            ],
                        ],
                        'required' => ['part_id', 'enabled'],
                        'additionalProperties' => false,
                    ],
                    'description' => 'The full set of startup parts and the state each should end up in.',
                ],
            ],
            'required' => ['parts'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'parts' => 'required|array|min:1|max:100',
            'parts.*.part_id' => 'required|integer|distinct',
            'parts.*.enabled' => 'required|boolean',
        ];
    }

    public function permissions(): array
    {
        return [Permission::ACTION_STARTUP_UPDATE];
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function summarize(array $arguments): string
    {
        $parts = collect($arguments['parts'] ?? [])
            ->filter(fn ($part) => is_array($part) && isset($part['part_id']));

        $names = $this->partNames($parts->pluck('part_id')->all());
        $label = fn (array $part) => $names[$part['part_id']] ?? "startup part #{$part['part_id']}";

        $on = $parts->filter(fn (array $part) => $this->enabled($part))->map($label)->all();
        $off = $parts->reject(fn (array $part) => $this->enabled($part))->map($label)->all();

        $summary = [];

        if ($on !== []) {
            $summary[] = 'turn on '.implode(', ', $on);
        }

        if ($off !== []) {
            $summary[] = 'turn off '.implode(', ', $off);
        }

        return 'Change the startup command: '.(implode(' and ', $summary) ?: 'no changes')
            .' (takes effect on the next server restart)';
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;
        $eggParts = $server->egg?->startupParts;

        if (! $eggParts || $eggParts->isEmpty()) {
            throw new ChatbotException('This server does not have configurable startup parts. Use update_startup_variable to change its startup instead.');
        }

        $requested = collect($arguments['parts'])
            ->map(fn (array $part) => [
                'part_id' => (int) $part['part_id'],
                // The boolean rule accepts "1" and "0" as well as real booleans,
                // so normalize before anything decides what is enabled.
                'enabled' => $this->enabled($part),
            ]);

        if ($invalid = $requested->pluck('part_id')->diff($eggParts->pluck('id'))->first()) {
            throw new ChatbotException("Invalid startup part ID: $invalid. Use get_startup to see the parts this server actually has.");
        }

        foreach ($eggParts->where('required', true) as $part) {
            $choice = $requested->firstWhere('part_id', $part->id);

            if (! ($choice['enabled'] ?? $part->default_enabled)) {
                throw new ChatbotException("The startup part '$part->name' is required and cannot be disabled.");
            }
        }

        // Only parts that were explicitly sent are persisted; omitted parts fall
        // back to their default state, exactly as the panel's own endpoint does.
        $server->update(['startup_parts' => $requested->values()->all()]);

        return [
            'startup_command' => $this->startupCommandService->handle($server->refresh()),
            'raw_startup_command' => $server->startup,
            'startup_parts' => $requested->values()->all(),
            'message' => 'The startup parts were saved. The server must be restarted before the change takes effect.',
        ];
    }

    private function enabled(array $part): bool
    {
        return filter_var($part['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolves part names purely so the confirmation prompt can say "turn off
     * Bungeecord support" rather than "turn off startup part #7".
     *
     * @return array<int, string>
     */
    private function partNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            return EggStartupPart::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
