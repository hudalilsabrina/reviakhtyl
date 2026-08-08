<?php

namespace App\Services\Chatbot\Agents;

use App\Services\Chatbot\ToolContext;

/**
 * Holds every agent the panel ships and narrows them down to the set a given
 * user may be routed to on a given server.
 */
class AgentRegistry
{
    /** @var array<string, ChatbotAgent> */
    private array $agents = [];

    /**
     * Per-context result of availableFor(). Filtering runs the shared tool
     * permission checks through each agent, and the router asks for the
     * available set once per turn plus once per delegate resolution.
     *
     * @var array<string, array<string, ChatbotAgent>>
     */
    private array $available = [];

    public function __construct()
    {
        foreach (config('chatbot.agents', []) as $class) {
            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $agent = app($class);

            if ($agent instanceof ChatbotAgent) {
                $this->agents[$agent->id()] = $agent;
            }
        }
    }

    /**
     * @return array<string, ChatbotAgent>
     */
    public function all(): array
    {
        return $this->agents;
    }

    /**
     * Every agent this context may be routed to, keyed by id.
     *
     * @return array<string, ChatbotAgent>
     */
    public function availableFor(ToolContext $context): array
    {
        $key = $context->server->id.':'.$context->user->id;

        return $this->available[$key] ??= array_filter(
            $this->agents,
            fn (ChatbotAgent $agent) => $agent->can($context),
        );
    }

    /**
     * Resolves an agent by id, but only if the context may actually use it.
     * Returns null for unknown and unauthorized ids alike so a hallucinated
     * agent id cannot be distinguished from a forbidden one.
     */
    public function resolveFor(ToolContext $context, string $id): ?ChatbotAgent
    {
        return $this->availableFor($context)[$id] ?? null;
    }
}
