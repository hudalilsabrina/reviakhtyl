<?php

use App\Enum\ChatbotToolGroup;
use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\ChatbotAgentRun;
use App\Models\ChatbotMessage;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\Agents\ChatbotAgent;
use App\Services\Chatbot\Agents\FilesAgent;
use App\Services\Chatbot\ChatbotSettings;
use App\Services\Chatbot\Data\ChatCompletion;
use App\Services\Chatbot\Data\ToolCall;
use App\Services\Chatbot\OpenAiClient;
use App\Services\Chatbot\RoutingService;
use App\Services\Chatbot\SystemPromptBuilder;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\ToolExecutor;
use App\Services\Chatbot\ToolRegistry;
use Mockery\MockInterface;

/**
 * runAgent() is the heart of the orchestration security model: a sub-agent may
 * only ever execute the tools of its own groups, even when the user holds more
 * tools and the model hallucinates a call outside the agent's scope. The loop
 * and its transcript are exercised here without a database: the run is built
 * without its constructor and only its in-memory properties are written.
 *
 * The service is built without its constructor; collaborators are injected by
 * reflection. $client, $executor and $promptBuilder are mocked; the registry is
 * the real registered tool set with a stubbed group-toggle answer, exactly as
 * AgentRegistryTest does.
 */

/**
 * Sets a property through reflection. Pest files share a process, but helper
 * functions declared in one file are not visible to another, so this mirrors
 * AgentRegistryTest's own helper.
 */
function routingReflectionSet(object $object, string $property, mixed $value): void
{
    $property = new ReflectionProperty($object, $property);
    $property->setValue($object, $value);
}

class TrustedRunContext extends ToolContext
{
    public function __construct()
    {
        parent::__construct(
            (new ReflectionClass(Server::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(User::class))->newInstanceWithoutConstructor(),
        );
    }

    public function can(string $permission): bool
    {
        return true;
    }

    public function canAll(array $permissions): bool
    {
        return true;
    }
}

/**
 * A registry over the real registered tools, answering group toggles from
 * $enabledGroups. Every tool's own permission check is trusted by the context.
 *
 * @param  string[]  $enabledGroups
 */
function runAgentRegistry(array $enabledGroups): ToolRegistry
{
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $settings = Mockery::mock(ChatbotSettings::class);
    $settings->shouldReceive('isToolGroupEnabled')
        ->andReturnUsing(fn (ChatbotToolGroup $group) => in_array($group->value, $enabledGroups, true));

    $tools = [];

    foreach ($config['tools'] as $class) {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $tools[$tool->name()] = $tool;
    }

    $registry = (new ReflectionClass(ToolRegistry::class))->newInstanceWithoutConstructor();

    routingReflectionSet($registry, 'settings', $settings);
    routingReflectionSet($registry, 'tools', $tools);

    return $registry;
}

/**
 * A RoutingService with mocked client/executor/prompt builder and a real
 * registry, all injected through reflection so no container is touched.
 *
 * @return array{service: RoutingService, client: MockInterface, executor: MockInterface}
 */
function routingServiceWithRegistry(ToolRegistry $registry): array
{
    $settings = Mockery::mock(ChatbotSettings::class);
    $settings->shouldReceive('maxIterations')->andReturn(5);
    $settings->shouldReceive('requiresConfirmation')->andReturn(true);

    $client = Mockery::mock(OpenAiClient::class);
    $executor = Mockery::mock(ToolExecutor::class);
    $promptBuilder = Mockery::mock(SystemPromptBuilder::class);
    $promptBuilder->shouldReceive('buildForAgent')->andReturn('You are the files agent.');

    $service = (new ReflectionClass(RoutingService::class))->newInstanceWithoutConstructor();
    routingReflectionSet($service, 'settings', $settings);
    routingReflectionSet($service, 'client', $client);
    routingReflectionSet($service, 'registry', $registry);
    routingReflectionSet($service, 'executor', $executor);
    routingReflectionSet($service, 'promptBuilder', $promptBuilder);

    return ['service' => $service, 'client' => $client, 'executor' => $executor];
}

/**
 * A ChatbotAgentRun whose save() is a no-op: runAgent() writes the run's
 * transcript and status attributes in memory and the test reads them back, with
 * no database behind them. Built without its constructor like every Eloquent
 * model in this suite.
 */
class FakeRun extends ChatbotAgentRun
{
    public function save(array $options = []): bool
    {
        return true;
    }
}

function runAgentRun(): ChatbotAgentRun
{
    $run = (new ReflectionClass(FakeRun::class))->newInstanceWithoutConstructor();
    $run->forceFill(['agent_key' => 'files']);

    return $run;
}

/**
 * A RoutingService over every registered agent group, with its mocked client
 * and executor returned for stubbing.
 *
 * @return array{service: RoutingService, client: MockInterface, executor: MockInterface}
 */
function runAgentService(): array
{
    $built = routingServiceWithRegistry(runAgentRegistry(array_column(ChatbotToolGroup::cases(), 'value')));

    return [
        'service' => $built['service'],
        'client' => $built['client'],
        'executor' => $built['executor'],
    ];
}

function invokeRunAgent(
    RoutingService $service,
    ChatbotAgentRun $run,
    ChatbotAgent $agent,
    array $calls,
): array {
    return (new ReflectionMethod(RoutingService::class, 'runAgent'))
        ->invoke($service, $run, $agent, new TrustedRunContext(), 'Do the thing.');
}

function filesAgent(): FilesAgent
{
    return (new ReflectionClass(FilesAgent::class))->newInstanceWithoutConstructor();
}

it('runs the agent\'s own in-scope tools and answers after the loop settles', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    // One tool batch (a non-destructive in-scope read), then a plain answer.
    $client->shouldReceive('chat')
        ->andReturn(
            new ChatCompletion(
                content: 'Reading the log.',
                toolCalls: [new ToolCall('call_1', 'list_files', ['directory' => '/'])],
                finishReason: 'tool_calls',
                usage: [],
            ),
            new ChatCompletion(
                content: 'Here is the directory listing.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ),
        );

    $executor->shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ToolContext::class), 'list_files', ['directory' => '/'])
        ->andReturn(['ok' => true]);

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    expect($outcome['status'])->toBe('answer')
        ->and($outcome['content'])->toBe('Here is the directory listing.')
        ->and($run->status)->toBe(ChatbotAgentRun::STATUS_COMPLETE);
});

it('never executes a tool outside the agent\'s scope', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    // The model hallucinates a power call while running as the files agent.
    // The user does hold power_action (every group is enabled), so without
    // scope enforcement this would run; it must be rejected instead.
    $client->shouldReceive('chat')
        ->andReturn(
            new ChatCompletion(
                content: 'Restarting the server.',
                toolCalls: [
                    new ToolCall('call_power', 'power_action', ['action' => 'restart']),
                    new ToolCall('call_files', 'list_files', ['directory' => '/']),
                ],
                finishReason: 'tool_calls',
                usage: [],
            ),
            new ChatCompletion(
                content: 'Done.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ),
        );

    $executor->shouldReceive('execute')
        ->once()
        ->with(Mockery::type(ToolContext::class), 'list_files', ['directory' => '/'])
        ->andReturn(['ok' => true]);

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    expect($outcome['status'])->toBe('answer');

    // Both calls were answered in the transcript: the in-scope one by the
    // executor's result, the out-of-scope one by a scope rejection.
    $entries = array_values(array_filter(
        $run->transcript,
        fn (array $entry) => ($entry['role'] ?? '') === ChatbotMessage::ROLE_TOOL,
    ));

    expect($entries)->toHaveCount(2);

    $byId = collect($entries)->keyBy('tool_call_id');

    expect($byId['call_power']['content'])->toContain("outside this agent's scope")
        ->and($byId['call_files']['content'])->toContain('"ok":true');
});

it('answers out-of-scope calls before projecting an approval so no call id dangles', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    // The files agent proposes a destructive in-scope delete PLUS an out-of-
    // scope power action. The delete pauses for approval; the power call must
    // not be projected and must be answered in the transcript so the resumed
    // provider never sees an unanswered id.
    $client->shouldReceive('chat')
        ->once()
        ->andReturn(new ChatCompletion(
            content: 'I will delete and restart.',
            toolCalls: [
                new ToolCall('call_delete', 'delete_files', ['paths' => ['/x']]),
                new ToolCall('call_power', 'power_action', ['action' => 'restart']),
            ],
            finishReason: 'tool_calls',
            usage: [],
        ));

    $executor->shouldReceive('execute')->never();

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    expect($outcome['status'])->toBe('pending')
        ->and($outcome['calls'])->toHaveCount(1)
        ->and($outcome['calls'][0]->name)->toBe('delete_files')
        ->and($run->status)->toBe(ChatbotAgentRun::STATUS_AWAITING_CONFIRMATION);

    $toolEntries = array_values(array_filter(
        $run->transcript,
        fn (array $entry) => ($entry['role'] ?? '') === ChatbotMessage::ROLE_TOOL,
    ));

    expect($toolEntries)->toHaveCount(1)
        ->and($toolEntries[0]['tool_call_id'])->toBe('call_power')
        ->and($toolEntries[0]['content'])->toContain("outside this agent's scope");
});

it('answers a single out-of-scope call without running the executor', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    $client->shouldReceive('chat')
        ->andReturn(
            new ChatCompletion(
                content: 'Firing up the console.',
                toolCalls: [new ToolCall('call_power', 'power_action', ['action' => 'restart'])],
                finishReason: 'tool_calls',
                usage: [],
            ),
            new ChatCompletion(
                content: 'I could not do that.',
                toolCalls: [],
                finishReason: 'stop',
                usage: [],
            ),
        );

    $executor->shouldReceive('execute')->never();

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    // The agent still answers so the run closes normally rather than stalling
    // the router with an unexecuted call left dangling.
    expect($outcome['status'])->toBe('answer')
        ->and($run->status)->toBe(ChatbotAgentRun::STATUS_COMPLETE);

    $entries = array_values(array_filter(
        $run->transcript,
        fn (array $entry) => ($entry['role'] ?? '') === ChatbotMessage::ROLE_TOOL,
    ));

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['tool_call_id'])->toBe('call_power')
        ->and($entries[0]['content'])->toContain("outside this agent's scope");
});

it('digests a destructive scoped call into a pending approval', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    $client->shouldReceive('chat')
        ->once()
        ->andReturn(new ChatCompletion(
            content: 'Deleting the backups.',
            toolCalls: [new ToolCall('call_del', 'delete_files', ['paths' => ['/backups']])],
            finishReason: 'tool_calls',
            usage: [],
        ));

    $executor->shouldReceive('execute')->never();

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    expect($outcome['status'])->toBe('pending')
        ->and($outcome['calls'][0]->id)->toBe('call_del')
        ->and($run->status)->toBe(ChatbotAgentRun::STATUS_AWAITING_CONFIRMATION);
});

it('localises a provider failure to the run instead of throwing', function () {
    [$service, $client, $executor] = array_values(runAgentService());

    $client->shouldReceive('chat')
        ->once()
        ->andThrow(new ChatbotException('The AI provider rejected the request: boom'));

    $run = runAgentRun();
    $outcome = invokeRunAgent($service, $run, filesAgent(), []);

    expect($outcome['status'])->toBe('answer')
        ->and($outcome['content'])->toContain('boom')
        ->and($run->status)->toBe(ChatbotAgentRun::STATUS_FAILED)
        ->and($run->result)->toContain('boom');
});
