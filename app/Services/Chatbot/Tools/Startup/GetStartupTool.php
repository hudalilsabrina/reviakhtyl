<?php

namespace App\Services\Chatbot\Tools\Startup;

use App\Enum\ChatbotToolGroup;
use App\Models\EggStartupPart;
use App\Models\EggVariable;
use App\Models\Permission;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use App\Services\Servers\StartupCommandService;

class GetStartupTool extends ChatbotTool
{
    public function __construct(private StartupCommandService $startupCommandService) {}

    public function name(): string
    {
        return 'get_startup';
    }

    public function description(): string
    {
        return 'Get the command the server is launched with and every startup variable the user is allowed to see: its current value, its default, the validation rules a new value must satisfy, and whether it can be edited at all. Variables the egg marks as hidden are never returned. If the egg supports modular startup, the optional startup parts and whether each is currently switched on are returned too. Call this before changing anything with update_startup_variable or update_startup_parts.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Startup;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function permissions(): array
    {
        return [Permission::ACTION_STARTUP_READ];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $server = $context->server;

        $result = [
            'startup_command' => $this->startupCommandService->handle($server),
            'raw_startup_command' => $server->startup,
            'docker_image' => $server->image,
            // Named "entries" so an oversized variable list is trimmed by the
            // executor rather than discarded wholesale.
            'entries' => $server->variables()
                ->where('user_viewable', true)
                ->get()
                ->map(fn (EggVariable $variable) => [
                    'name' => $variable->name,
                    'description' => $variable->description,
                    'env_variable' => $variable->env_variable,
                    'server_value' => $variable->server_value,
                    'default_value' => $variable->default_value,
                    'rules' => $variable->rules,
                    'is_editable' => $variable->user_editable,
                ])
                ->values()
                ->all(),
        ];

        $eggParts = $server->egg?->startupParts;

        if ($eggParts && $eggParts->isNotEmpty()) {
            // Only the parts the user explicitly toggled are stored; every
            // other part falls back to the egg's default state.
            $choices = $server->startupPartChoices();

            $result['has_modular_startup'] = true;
            $result['startup_parts'] = $eggParts->map(fn (EggStartupPart $part) => [
                'id' => $part->id,
                'name' => $part->name,
                'description' => $part->description,
                'required' => $part->required,
                'default_enabled' => $part->default_enabled,
                'user_enabled' => $choices[$part->id] ?? $part->default_enabled,
            ])->values()->all();
        }

        return $result;
    }
}
