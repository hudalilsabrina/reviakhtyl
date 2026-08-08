<?php

namespace App\Services\Chatbot\Agents;

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\ToolRegistry;

/**
 * A narrow specialist the orchestrating router may delegate work to.
 *
 * Each agent declares the tool groups that make up its toolkit and a system
 * directive describing its narrow role. Agent scoping is defense-in-depth, not
 * the permission gate: an agent only ever holds the definitions of its own
 * groups, so an injected instruction cannot make it reach for tools outside
 * its domain. Every tool it does call is still checked by the shared
 * ToolExecutor path before it runs.
 */
abstract class ChatbotAgent
{
    public function __construct(protected ToolRegistry $registry) {}

    /**
     * The stable identifier the router uses when delegating. Must match
     * `^[a-zA-Z0-9_-]{1,64}$`.
     */
    abstract public function id(): string;

    /**
     * A human display name, used in the router's prompt and in the delegate
     * tool's summaries.
     */
    abstract public function name(): string;

    /**
     * The narrow role prompt fragment this agent runs under.
     */
    abstract public function systemDirective(): string;

    /**
     * @return ChatbotToolGroup[]
     */
    abstract public function toolGroups(): array;

    /**
     * Optional per-agent model override. v1 ships none: every agent uses the
     * panel model and null flows through to OpenAiClient.
     */
    public function model(): ?string
    {
        return null;
    }

    /**
     * Whether the agent can be routed to for this context: at least one of its
     * groups must be enabled panel-wide AND this user must hold the
     * permissions of at least one tool in those groups. The check runs over
     * ToolRegistry::availableFor(), which is memoized per server+user.
     */
    public function can(ToolContext $context): bool
    {
        foreach ($this->registry->availableFor($context) as $tool) {
            if (in_array($tool->group(), $this->toolGroups(), true)) {
                return true;
            }
        }

        return false;
    }
}
