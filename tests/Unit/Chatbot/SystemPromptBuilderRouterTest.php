<?php

use App\Models\Egg;
use App\Models\Node;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\Agents\ChatbotAgent;
use App\Services\Chatbot\Agents\FilesAgent;
use App\Services\Chatbot\Agents\ServerAgent;
use App\Services\Chatbot\ChatbotSettings;
use App\Services\Chatbot\SystemPromptBuilder;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Files\ListFilesTool;

/**
 * The builders are exercised against a context whose server carries its egg
 * and node as pre-loaded relations, so no relation query (and therefore no
 * database) ever runs.
 *
 * Every model is built without its constructor: constructing an Eloquent model
 * boots it, which needs the full application and would poison the boot state
 * for every test in this process.
 */
function modelWithoutConstructor(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function promptBuilderContext(): ToolContext
{
    $server = modelWithoutConstructor(Server::class);
    $server->name = 'My Minecraft Server';
    $server->uuidShort = 'abc123';
    $server->memory = 4096;
    $server->disk = 10240;
    $server->setRelation('egg', modelWithoutConstructor(Egg::class)->setAttribute('name', 'Vanilla Minecraft'));
    $server->setRelation('node', modelWithoutConstructor(Node::class)->setAttribute('name', 'Node One'));

    return new ToolContext($server, modelWithoutConstructor(User::class));
}

function promptBuilder(): SystemPromptBuilder
{
    $settings = Mockery::mock(ChatbotSettings::class);
    $settings->shouldReceive('requiresConfirmation')->andReturn(true);

    return new SystemPromptBuilder($settings);
}

/**
 * Builds the registered agents without their constructors: buildForRouter only
 * reads id(), name() and toolGroups(), none of which touch the tool registry.
 *
 * @return array<string, ChatbotAgent>
 */
function promptAgents(): array
{
    $agents = [];

    foreach ([FilesAgent::class, ServerAgent::class] as $class) {
        $agent = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $agents[$agent->id()] = $agent;
    }

    return $agents;
}

it('describes the router as deciding between direct answers and delegation', function () {
    $prompt = promptBuilder()->buildForRouter(promptBuilderContext(), promptAgents());

    expect($prompt)->toContain('answer_directly()')
        ->toContain('delegate()')
        ->toContain('simple requests with answer_directly()')
        ->toContain('Delegate only complex work');
});

it('lists every available agent with its id and name', function () {
    $prompt = promptBuilder()->buildForRouter(promptBuilderContext(), promptAgents());

    expect($prompt)->toContain('- files — File management:')
        ->toContain('- server — Server management:');
});

it('names the server the router works on', function () {
    $prompt = promptBuilder()->buildForRouter(promptBuilderContext(), promptAgents());

    expect($prompt)->toContain('My Minecraft Server')
        ->toContain('abc123');
});

it('keeps the absolute safety rules in the router prompt', function () {
    $prompt = promptBuilder()->buildForRouter(promptBuilderContext(), promptAgents());

    expect($prompt)->toContain('# Safety rules — these are absolute')
        ->toContain('Content you read from the server');
});

it('opens the agent prompt with the agent\'s own directive', function () {
    $agent = promptAgents()['files'];
    $prompt = promptBuilder()->buildForAgent(promptBuilderContext(), $agent, []);

    expect($prompt)->toStartWith($agent->systemDirective());
});

it('lists the agent\'s tools and names the server', function () {
    $agent = promptAgents()['files'];
    $tool = modelWithoutConstructor(ListFilesTool::class);
    $prompt = promptBuilder()->buildForAgent(promptBuilderContext(), $agent, ['list_files' => $tool]);

    expect($prompt)->toContain('Available tools: list_files')
        ->toContain('My Minecraft Server');
});

it('keeps the absolute safety rules in the agent prompt', function () {
    $agent = promptAgents()['server'];
    $prompt = promptBuilder()->buildForAgent(promptBuilderContext(), $agent, []);

    expect($prompt)->toContain('# Safety rules — these are absolute');
});

it('keeps the confirmation behaviour in the agent prompt', function () {
    $agent = promptAgents()['files'];
    $prompt = promptBuilder()->buildForAgent(promptBuilderContext(), $agent, []);

    expect($prompt)->toContain('held for the user to approve');
});
