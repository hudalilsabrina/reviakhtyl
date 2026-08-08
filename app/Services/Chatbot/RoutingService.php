<?php

namespace App\Services\Chatbot;

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotAgentRun;
use App\Models\ChatbotConversation;
use App\Models\ChatbotMessage;
use App\Services\Chatbot\Agents\AgentRegistry;
use App\Services\Chatbot\Agents\ChatbotAgent;
use App\Services\Chatbot\Concerns\ManagesChatbotTurns;
use App\Services\Chatbot\Data\ToolCall;
use App\Services\Chatbot\Tools\ChatbotTool;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The orchestrating router. A turn that reaches this service is decomposed by
 * one model call into delegates, each of which is handed to a narrow
 * sub-agent (RoutingService::runAgent) that holds only its own group's tools.
 *
 * The router itself never holds a panel tool — its only tool is delegate() —
 * so it is incapable of a side effect of its own. Every tool an agent calls
 * still goes through the shared ToolExecutor permission check.
 */
class RoutingService
{
    use ManagesChatbotTurns;

    /**
     * How many router → delegate → result cycles a single turn may take before
     * the router is forced to answer. Kept deliberately small: each cycle is a
     * provider call plus up to one sub-agent loop.
     */
    private const MAX_ROUTER_ITERATIONS = 3;

    /** How many delegate() calls from one router response are honoured. */
    private const MAX_DELEGATES_PER_TURN = 3;

    /** Sub-agent answers longer than this are truncated in the router's digest. */
    private const RESULT_DIGEST_LENGTH = 2000;

    public function __construct(
        private ChatbotSettings $settings,
        private OpenAiClient $client,
        private ToolRegistry $registry,
        private ToolExecutor $executor,
        private SystemPromptBuilder $promptBuilder,
        private AgentRegistry $agents,
    ) {}

    /**
     * The router's only tool. Built inline rather than as a ChatbotTool class
     * because it is not a panel capability: it must never be reachable by a
     * sub-agent, and nothing about it is configurable.
     *
     * @return array<string, mixed>
     */
    public function delegateDefinition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'delegate',
                'description' => 'Ask a specialized agent to perform work on the server. Pass the exact request and the agent ids to use. Delegate to ONE agent per call and wait for the result before calling again.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'request' => ['type' => 'string', 'description' => 'The task for the agent, written as a direct instruction.'],
                        'to_agent_ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Exactly one agent id.'],
                        'context_budget' => ['type' => 'integer', 'description' => 'Optional. Not enforced in this version; keep requests focused.'],
                    ],
                    'required' => ['request', 'to_agent_ids'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * Routes one orchestrated turn: resumes any paused sub-agent run first, then
     * runs the router loop, delegating to sub-agents until an answer, an
     * approval stop, or the iteration budget is exhausted.
     *
     * @return Collection<int, ChatbotMessage> the messages created this turn, in order
     */
    public function run(ChatbotConversation $conversation, ?callable $emit = null): Collection
    {
        $context = $this->contextFor($conversation);

        /** @var Collection<int, ChatbotMessage> $created */
        $created = collect();

        $pendingRun = ChatbotAgentRun::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', ChatbotAgentRun::STATUS_AWAITING_CONFIRMATION)
            ->reorder('id', 'desc')
            ->first();

        if ($pendingRun) {
            return $this->resumeRun($conversation, $context, $pendingRun, $created, $emit);
        }

        $agents = $this->agents->availableFor($context);

        if ($agents === []) {
            // Nothing this user may act on, so the provider is not even called:
            // a zero-tool router prompt would invite it to make things up.
            $answer = $this->persistAssistant($conversation, null, [
                'content' => 'I cannot help with anything here: this user\'s permissions do not allow any action on this server.',
            ]);

            $created->push($answer);
            $emit && $emit('message', ['message' => $answer]);

            return $created;
        }

        $systemPrompt = $this->promptBuilder->buildForRouter($context, $agents);
        $definitions = [$this->delegateDefinition()];

        for ($iteration = 0; $iteration < self::MAX_ROUTER_ITERATIONS; $iteration++) {
            $providerMessages = $this->routerProviderMessages($conversation, $context, $systemPrompt);

            // Streaming needs somewhere to put the text before the turn is
            // finished, so the row is written first and filled in as it arrives.
            $placeholder = null;

            if ($emit) {
                $placeholder = $this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_ASSISTANT,
                    'content' => null,
                ]);

                $created->push($placeholder);
                $emit('message', ['message' => $placeholder]);
            }

            try {
                // Sub-agent work is deliberately not streamed in v1: only the
                // router's own visible text is, and it streams via the flat
                // path's placeholder+delta contract.
                $completion = $this->client->chat($providerMessages, $definitions);
            } catch (ChatbotException $e) {
                $failed = $this->persistAssistant($conversation, $placeholder, [
                    'content' => $e->getMessage(),
                    'status' => ChatbotMessage::STATUS_FAILED,
                ]);

                $emit && $emit('message', ['message' => $failed]);

                return $placeholder ? $created : $created->push($failed);
            }

            if (! $completion->hasToolCalls()) {
                if (empty(trim((string) $completion->content))) {
                    // No text and no tool calls — nothing useful to show.
                    return $placeholder ? $created : $created;
                }

                $answer = $this->persistAssistant($conversation, $placeholder, [
                    'content' => $completion->content,
                    'reasoning' => $completion->reasoning,
                ]);

                $emit && $emit('message', ['message' => $answer]);

                return $placeholder ? $created : $created->push($answer);
            }

            $delegateCalls = array_slice($completion->toolCalls, 0, self::MAX_DELEGATES_PER_TURN);

            $assistant = $this->persistAssistant($conversation, $placeholder, [
                'content' => $completion->content,
                'reasoning' => $completion->reasoning,
                'tool_calls' => $this->describeDelegateCalls($context, $delegateCalls),
                'status' => ChatbotMessage::STATUS_COMPLETE,
            ]);

            if (! $placeholder) {
                $created->push($assistant);
            }

            $emit && $emit('message', ['message' => $assistant]);

            $calls = $assistant->tool_calls;
            $pendingCalls = null;

            foreach ($delegateCalls as $index => $call) {
                [$result, $pausedCalls] = $pendingCalls !== null
                    ? $this->skippedDelegateResult($context, $call)
                    : $this->executeDelegate($conversation, $context, $call);

                $pendingCalls ??= $pausedCalls;

                $calls[$index]['status'] = ($result['ok'] ?? false) ? 'executed' : 'failed';
                $calls[$index]['ok'] = (bool) ($result['ok'] ?? false);

                $emit && $emit('tool', ['uuid' => $assistant->uuid, 'call' => $calls[$index]]);

                // One tool message per delegate call, exactly like the flat
                // loop: the provider rejects the next request if a call id is
                // left unanswered. Remaining calls in a paused batch are
                // answered with the skipped digest rather than executed.
                $created->push($this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_TOOL,
                    'tool_call_id' => $call->id,
                    'tool_name' => 'delegate',
                    'content' => $this->encode($result),
                ]));
            }

            if ($pendingCalls !== null) {
                // The sub-agent's pending calls take over the router's
                // message: the user approves or denies these, and
                // resolveConfirmation runs them directly.
                $projected = $this->persistAssistant($conversation, $assistant, [
                    'tool_calls' => $this->describeCalls($context, $pendingCalls, 'pending'),
                    'status' => ChatbotMessage::STATUS_AWAITING_CONFIRMATION,
                ]);

                $emit && $emit('message', ['message' => $projected]);

                return $created;
            }

            $assistant->update(['tool_calls' => $calls]);
        }

        $exhausted = $this->store($conversation, [
            'role' => ChatbotMessage::ROLE_ASSISTANT,
            'content' => 'I stopped after taking too many steps in a row without reaching an answer. Tell me what to focus on and I will try a narrower approach.',
        ]);

        $emit && $emit('message', ['message' => $exhausted]);

        return $created->push($exhausted);
    }

    /**
     * Continues a paused sub-agent run after the user decided on its proposed
     * calls. The decision's tool results were just written by
     * resolveConfirmation; they are appended to the run's transcript and the
     * sub-agent loop resumes. In v1 the router does not compose on this path —
     * the sub-agent's final answer is the visible one.
     */
    private function resumeRun(
        ChatbotConversation $conversation,
        ToolContext $context,
        ChatbotAgentRun $run,
        Collection $created,
        ?callable $emit,
    ): Collection {
        try {
            $pendingIds = $this->pendingCallIds($run);

            // Self-healing guard: the projected message that started the pause
            // may have been deleted (regenerate, destroyMessage), leaving the
            // run stale. Its tool results would be gone with it; close the run
            // and let the router start fresh instead of resuming from nowhere.
            $results = $conversation->messages()
                ->where('role', ChatbotMessage::ROLE_TOOL)
                ->whereIn('tool_call_id', $pendingIds)
                ->get();

            if ($results->isEmpty()) {
                $run->forceFill(['status' => ChatbotAgentRun::STATUS_COMPLETE])->save();

                return $created;
            }

            $transcript = $run->transcript ?? [];

            foreach ($results as $result) {
                $transcript[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) $result->tool_call_id,
                    'content' => (string) $result->content,
                ];
            }

            $run->forceFill([
                'transcript' => $transcript,
                'status' => ChatbotAgentRun::STATUS_RUNNING,
            ])->save();

            $agent = $this->agents->resolveFor($context, $run->agent_key);

            if (! $agent) {
                throw new ChatbotException("The agent \"{$run->agent_key}\" is no longer available for this conversation.");
            }

            $outcome = $this->runAgent($run, $agent, $context, $run->request);

            $placeholder = null;

            if ($emit) {
                $placeholder = $this->store($conversation, [
                    'role' => ChatbotMessage::ROLE_ASSISTANT,
                    'content' => null,
                ]);

                $created->push($placeholder);
                $emit('message', ['message' => $placeholder]);
            }

            $answer = $this->persistAssistant($conversation, $placeholder, [
                'content' => $outcome['content'],
            ]);

            if (! $placeholder) {
                $created->push($answer);
            }

            $emit && $emit('message', ['message' => $answer]);

            return $created;
        } catch (ChatbotException $e) {
            $failed = $this->persistAssistant($conversation, null, [
                'content' => $e->getMessage(),
                'status' => ChatbotMessage::STATUS_FAILED,
            ]);

            $created->push($failed);
            $emit && $emit('message', ['message' => $failed]);

            return $created;
        }
    }

    /**
     * The id of every tool call the paused assistant message projected. They
     * live in the run's transcript, which is the only place the sub-agent's
     * pending calls survive the user's decision.
     *
     * @return string[]
     */
    private function pendingCallIds(ChatbotAgentRun $run): array
    {
        foreach (array_reverse($run->transcript ?? []) as $entry) {
            if (is_array($entry) && ($entry['role'] ?? '') === ChatbotMessage::ROLE_ASSISTANT && ! empty($entry['tool_calls'])) {
                return array_column($entry['tool_calls'], 'id');
            }
        }

        return [];
    }

    /**
     * Validates one delegate call, runs the first resolved agent, and returns
     * the digest handed back to the router plus the run's pending calls when
     * the sub-agent paused for approval.
     *
     * @return array{0: array<string, mixed>, 1: ToolCall[]|null} the result digest; the pending calls, or null when the sub-agent answered
     */
    private function executeDelegate(ChatbotConversation $conversation, ToolContext $context, ToolCall $call): array
    {
        $arguments = $call->arguments;

        $request = $arguments['request'] ?? null;

        if (! is_string($request) || trim($request) === '') {
            return [['ok' => false, 'error' => 'The delegate call carried no request text. Ask the router to restate the task and try again.'], null];
        }

        $agentIds = $arguments['to_agent_ids'] ?? null;

        if (! is_array($agentIds) || $agentIds === [] || ! collect($agentIds)->every(fn ($id) => is_string($id) && $id !== '')) {
            return [['ok' => false, 'error' => 'The delegate call carried no valid agent ids. Pass exactly one known agent id.'], null];
        }

        $resolved = [];

        foreach ($agentIds as $id) {
            $agent = $this->agents->resolveFor($context, (string) $id);

            if (! $agent) {
                return [['ok' => false, 'error' => "The agent \"$id\" is not available on this server for this user."], null];
            }

            $resolved[] = $agent;
        }

        // Only the first agent in the list runs. v1 keeps the delegation
        // strictly one-agent-per-call; a multi-id call is not an error, but
        // nothing beyond the first id is honoured.
        $agent = $resolved[0];

        $run = ChatbotAgentRun::create([
            'uuid' => Str::uuid()->toString(),
            'conversation_id' => $conversation->id,
            'agent_key' => $agent->id(),
            'request' => trim($request),
        ]);

        $outcome = $this->runAgent($run, $agent, $context, trim($request));

        if ($outcome['status'] === 'pending') {
            return [[
                'ok' => true,
                'status' => 'awaiting_confirmation',
                'note' => "The {$agent->name()} agent is waiting for the user to approve its proposed actions before continuing.",
            ], $outcome['calls']];
        }

        return [[
            'ok' => true,
            'agent' => $agent->id(),
            'result' => Str::limit((string) $outcome['content'], self::RESULT_DIGEST_LENGTH),
        ], null];
    }

    /**
     * The digest for a delegate call that never started because an earlier
     * agent in the same batch paused the turn.
     *
     * @return array{0: array<string, mixed>, 1: null}
     */
    private function skippedDelegateResult(ToolContext $context, ToolCall $call): array
    {
        $ids = $call->arguments['to_agent_ids'] ?? null;
        $id = is_array($ids) && $ids !== [] ? (string) $ids[0] : '?';
        $agent = $this->agents->resolveFor($context, $id);

        return [[
            'ok' => false,
            'error' => 'The '.($agent?->name() ?? $id).' agent was not started because the turn paused for the user to approve another agent\'s action.',
        ], null];
    }

    /**
     * Runs one sub-agent's private loop until it answers, pauses for
     * approval, or exhausts its iteration budget. The exchange lives in the
     * run's transcript; the router only ever sees the outcome digest.
     *
     * @return array{status: 'answer'|'pending', content: ?string, calls: ToolCall[]}
     */
    private function runAgent(
        ChatbotAgentRun $run,
        ChatbotAgent $agent,
        ToolContext $context,
        string $request,
    ): array {
        $tools = array_filter(
            $this->registry->availableFor($context),
            fn (ChatbotTool $tool) => in_array($tool->group(), $agent->toolGroups(), true),
        );

        $definitions = array_values(array_map(fn (ChatbotTool $tool) => $tool->definition(), $tools));

        $transcript = $run->transcript;

        if ($transcript === null) {
            $transcript = [
                ['role' => 'system', 'content' => $this->promptBuilder->buildForAgent($context, $agent, $tools)],
                ['role' => 'user', 'content' => $request],
            ];
        }

        for ($iteration = 0; $iteration < $this->settings->maxIterations(); $iteration++) {
            // v1 agents all return null, so this always falls back to the
            // panel model; the override is plumbed so a per-agent model needs
            // no service changes.
            $completion = $this->client->chat($transcript, $definitions, $agent->model());

            if (! $completion->hasToolCalls()) {
                $transcript[] = ['role' => 'assistant', 'content' => $completion->content];

                $run->forceFill([
                    'transcript' => $transcript,
                    'result' => $completion->content,
                    'status' => ChatbotAgentRun::STATUS_COMPLETE,
                ])->save();

                return ['status' => 'answer', 'content' => $completion->content, 'calls' => []];
            }

            $needsApproval = $this->settings->requiresConfirmation()
                && $this->containsDestructiveCall($context, $completion->toolCalls);

            if ($needsApproval) {
                // The transcript keeps the assistant message WITH its tool
                // calls, so the resume path can replay the exchange and answer
                // the same call ids with the user's decision.
                $transcript[] = [
                    'role' => 'assistant',
                    'content' => $completion->content,
                    'tool_calls' => array_map(fn (ToolCall $call) => $call->toArray(), $completion->toolCalls),
                ];

                $run->forceFill([
                    'transcript' => $transcript,
                    'status' => ChatbotAgentRun::STATUS_AWAITING_CONFIRMATION,
                ])->save();

                return ['status' => 'pending', 'content' => $completion->content, 'calls' => $completion->toolCalls];
            }

            $calls = array_slice($completion->toolCalls, 0, self::MAX_CALLS_PER_TURN);
            $dropped = count($completion->toolCalls) - count($calls);

            $transcript[] = [
                'role' => 'assistant',
                'content' => $completion->content,
                'tool_calls' => array_map(fn (ToolCall $call) => $call->toArray(), $calls),
            ];

            foreach ($calls as $call) {
                $result = $this->executor->execute($context, $call->name, $call->arguments);

                if ($dropped > 0) {
                    // Tell the model why the rest went missing, so it narrows
                    // its next attempt instead of working from partial results.
                    $result['note'] = 'Only the first '.self::MAX_CALLS_PER_TURN.' tool calls of this turn were run; '
                        ."$dropped more were discarded. Ask for fewer things at once.";
                }

                $transcript[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call->id,
                    'content' => $this->encode($result),
                ];
            }

            $run->forceFill(['transcript' => $transcript])->save();
        }

        $exhausted = 'I stopped after taking too many steps in a row without reaching an answer. Tell me what to focus on and I will try a narrower approach.';

        $run->forceFill([
            'transcript' => $transcript,
            'result' => $exhausted,
            'status' => ChatbotAgentRun::STATUS_COMPLETE,
        ])->save();

        return ['status' => 'answer', 'content' => $exhausted, 'calls' => []];
    }

    /**
     * @param  ToolCall[]  $calls
     */
    private function containsDestructiveCall(ToolContext $context, array $calls): bool
    {
        foreach ($calls as $call) {
            $tool = $this->registry->resolveFor($context, $call->name);

            if ($tool?->isDestructive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The provider-shaped message list for the router, with the router's
     * system prompt — the same windowing, digesting and compaction as the flat
     * loop's buildProviderMessages.
     */
    private function routerProviderMessages(ChatbotConversation $conversation, ToolContext $context, string $systemPrompt): array
    {
        $reserved = $this->estimateTokens(['role' => 'system', 'content' => $systemPrompt])
            + $this->estimateTokens(['role' => 'system', 'content' => (string) $conversation->summary]);

        $history = $this->selectHistory($conversation, $reserved);

        $summary = $this->settings->compactionEnabled()
            ? $this->summarizeDropped($conversation, $history->first()?->id)
            : $conversation->summary;

        $messages = [[
            'role' => 'system',
            'content' => $systemPrompt,
        ]];

        if ($summary) {
            $messages[] = [
                'role' => 'system',
                'content' => "Summary of the earlier part of this conversation, which is no longer shown in full:\n".$summary,
            ];
        }

        $currentTurnStart = $this->currentTurnStartId($conversation);

        foreach ($history as $message) {
            $rendered = $this->renderForProvider($message, $message->id < $currentTurnStart);

            if ($rendered !== null) {
                $messages[] = $rendered;
            }
        }

        return $messages;
    }

    /**
     * Renders the router's own delegate calls for storage. delegate() is not
     * in the tool registry, so its summary is built here instead of through
     * ChatbotTool::summarize().
     *
     * @param  ToolCall[]  $calls
     */
    private function describeDelegateCalls(ToolContext $context, array $calls): array
    {
        return array_map(function (ToolCall $call) use ($context) {
            $ids = $call->arguments['to_agent_ids'] ?? null;
            $id = is_array($ids) && $ids !== [] ? (string) $ids[0] : '?';
            $agent = $this->agents->resolveFor($context, $id);
            $request = Str::limit((string) ($call->arguments['request'] ?? ''), 120);

            return [
                'id' => $call->id,
                'name' => 'delegate',
                'arguments' => $call->arguments,
                'summary' => 'Delegate to '.($agent?->name() ?? $id).': '.$request,
                'destructive' => false,
                'status' => 'pending',
                'ok' => null,
            ];
        }, $calls);
    }
}
