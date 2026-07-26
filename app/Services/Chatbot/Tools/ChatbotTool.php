<?php

namespace App\Services\Chatbot\Tools;

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\ToolContext;

/**
 * A single capability exposed to the model.
 *
 * Tools are the only way the assistant can touch a server. Each one declares
 * the subuser permissions it needs, which are checked twice: once when building
 * the tool list sent to the provider, and again immediately before execution.
 */
abstract class ChatbotTool
{
    /**
     * The identifier the model calls. Must match `^[a-zA-Z0-9_-]{1,64}$`.
     */
    abstract public function name(): string;

    /**
     * Explains to the model when to use this tool. This text is the entire
     * basis for the model's decision, so it should be specific about side
     * effects and about what the tool will not do.
     */
    abstract public function description(): string;

    abstract public function group(): ChatbotToolGroup;

    /**
     * The JSON Schema of the tool's arguments.
     */
    abstract public function parameters(): array;

    /**
     * Laravel validation rules applied to the model's arguments before the
     * tool runs. Models frequently invent or omit fields, so this is enforced
     * rather than trusted.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Subuser permissions the user must hold for this tool to be offered.
     *
     * @return string[]
     */
    public function permissions(): array
    {
        return [];
    }

    /**
     * Whether this tool should be offered to the model at all for the given
     * context. The default requires every declared permission; tools whose
     * required permission depends on the arguments (power actions, for example)
     * override this and re-check the specific permission inside handle().
     */
    public function isAvailableFor(ToolContext $context): bool
    {
        return $context->canAll($this->permissions());
    }

    /**
     * Destructive tools change or remove state in a way that is inconvenient or
     * impossible to undo. When confirmation is enabled panel-wide, the user has
     * to approve these calls before they run.
     */
    public function isDestructive(): bool
    {
        return false;
    }

    /**
     * A short human-readable summary of a pending call, shown in the approval
     * prompt so the user does not have to read raw JSON arguments.
     */
    public function summarize(array $arguments): string
    {
        $rendered = collect($arguments)
            ->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
            ->implode(', ');

        return $rendered === '' ? $this->name() : $this->name().' ('.$rendered.')';
    }

    /**
     * Runs the tool and returns a JSON-serializable result handed back to the
     * model. Throwing is fine: the executor converts exceptions into an error
     * result so the model can recover instead of the conversation failing.
     */
    abstract public function handle(ToolContext $context, array $arguments): array;

    /**
     * The provider-shaped definition sent alongside the conversation.
     */
    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => $this->parameters(),
            ],
        ];
    }

    /**
     * Helper for tools whose schema has no arguments.
     */
    protected function noParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
            'additionalProperties' => false,
        ];
    }
}
