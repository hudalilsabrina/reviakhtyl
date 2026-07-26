<?php

namespace App\Services\Chatbot;

use App\Services\Chatbot\Tools\ChatbotTool;

/**
 * Holds every tool the panel ships and narrows them down to the set a given
 * user may use on a given server.
 */
class ToolRegistry
{
    /** @var array<string, ChatbotTool> */
    private array $tools = [];

    /**
     * Per-context result of availableFor(). Filtering runs a Gate check for
     * every tool, and a single assistant turn asks for the available set once
     * per tool call plus once for the definitions.
     *
     * @var array<string, array<string, ChatbotTool>>
     */
    private array $available = [];

    public function __construct(private ChatbotSettings $settings)
    {
        foreach (config('chatbot.tools', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $tool = app($class);

            if ($tool instanceof ChatbotTool) {
                $this->tools[$tool->name()] = $tool;
            }
        }
    }

    /**
     * @return array<string, ChatbotTool>
     */
    public function all(): array
    {
        return $this->tools;
    }

    /**
     * Every tool the context is allowed to use: the group must be enabled by an
     * administrator and the user must hold all of the tool's permissions.
     *
     * @return array<string, ChatbotTool>
     */
    public function availableFor(ToolContext $context): array
    {
        $key = $context->server->id.':'.$context->user->id;

        return $this->available[$key] ??= array_filter(
            $this->tools,
            fn (ChatbotTool $tool) => $this->settings->isToolGroupEnabled($tool->group())
                && $tool->isAvailableFor($context),
        );
    }

    /**
     * Resolves a tool by name, but only if the context may actually use it.
     * Returns null for unknown, disabled, and unauthorized names alike so a
     * hallucinated tool name cannot be distinguished from a forbidden one.
     */
    public function resolveFor(ToolContext $context, string $name): ?ChatbotTool
    {
        return $this->availableFor($context)[$name] ?? null;
    }

    /**
     * The provider-shaped tool definitions for a context.
     */
    public function definitionsFor(ToolContext $context): array
    {
        return array_values(array_map(
            fn (ChatbotTool $tool) => $tool->definition(),
            $this->availableFor($context),
        ));
    }
}
