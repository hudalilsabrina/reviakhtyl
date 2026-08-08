<?php

use App\Enum\ChatbotToolGroup;
use App\Models\Server;
use App\Models\User;
use App\Services\Chatbot\Agents\AgentRegistry;
use App\Services\Chatbot\Agents\ChatbotAgent;
use App\Services\Chatbot\Agents\FilesAgent;
use App\Services\Chatbot\ChatbotSettings;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\ToolRegistry;

/**
 * The registries are built without their constructors: ToolRegistry's would
 * pull fifty tools out of the container, and the AgentRegistry's would pull
 * the ToolRegistry. Nothing exercised here touches tool constructor state, and
 * a subclass stands in for ToolContext so no Gate (and therefore no
 * application) is needed.
 */

/**
 * A ToolContext whose permission checks all pass, so the tools' own permission
 * declarations never gate the test.
 *
 * The server and user are built without their constructors: constructing an
 * Eloquent model boots it, which needs the full application and would poison
 * the boot state for every test in this process.
 */
class TrustedToolContext extends ToolContext
{
    public function __construct(?Server $server = null, ?User $user = null)
    {
        parent::__construct(
            $server ?? (new ReflectionClass(Server::class))->newInstanceWithoutConstructor(),
            $user ?? (new ReflectionClass(User::class))->newInstanceWithoutConstructor(),
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
 * Counts its can() invocations so AgentRegistry memoization is observable.
 */
class CountingFilesAgent extends FilesAgent
{
    public static int $calls = 0;

    public function can(ToolContext $context): bool
    {
        self::$calls++;

        return parent::can($context);
    }
}

function reflectionSet(object $object, string $property, mixed $value): void
{
    $property = new ReflectionProperty($object, $property);
    $property->setValue($object, $value);
}

function reflectionBuild(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

/**
 * A ToolRegistry holding the real registered tools but answering group toggles
 * from $enabledGroups, with every permission check trusted.
 *
 * @param  string[]  $enabledGroups
 */
function registryForGroups(array $enabledGroups): ToolRegistry
{
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $settings = Mockery::mock(ChatbotSettings::class);
    $settings->shouldReceive('isToolGroupEnabled')
        ->andReturnUsing(fn (ChatbotToolGroup $group) => in_array($group->value, $enabledGroups, true));

    $tools = [];

    foreach ($config['tools'] as $class) {
        $tool = reflectionBuild($class);
        $tools[$tool->name()] = $tool;
    }

    $registry = reflectionBuild(ToolRegistry::class);
    reflectionSet($registry, 'settings', $settings);
    reflectionSet($registry, 'tools', $tools);

    return $registry;
}

/**
 * Builds an AgentRegistry over the configured agent classes, each bound to
 * $registry. $fileClass swaps in a stand-in for the files agent.
 */
function registryOverAgents(ToolRegistry $registry, string $fileClass = FilesAgent::class): AgentRegistry
{
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $agents = [];

    foreach ($config['agents'] as $class) {
        if ($class === FilesAgent::class) {
            $class = $fileClass;
        }

        $agent = reflectionBuild($class);
        reflectionSet($agent, 'registry', $registry);
        $agents[$agent->id()] = $agent;
    }

    $agentRegistry = reflectionBuild(AgentRegistry::class);
    reflectionSet($agentRegistry, 'agents', $agents);

    return $agentRegistry;
}

beforeEach(function () {
    CountingFilesAgent::$calls = 0;
});

it('loads every agent from the config register', function () {
    $registry = registryOverAgents(registryForGroups(ChatbotToolGroup::defaults()));

    $ids = array_keys($registry->all());

    expect($ids)->toHaveCount(7)
        ->toContain('files', 'server', 'power', 'startup', 'mods', 'subusers', 'web');
});

it('offers every agent when all groups are enabled', function () {
    $groups = array_column(ChatbotToolGroup::cases(), 'value');
    $registry = registryOverAgents(registryForGroups($groups));

    $available = $registry->availableFor(new TrustedToolContext());

    expect(array_keys($available))->toBe(['files', 'server', 'power', 'startup', 'mods', 'subusers', 'web'])
        ->and($available)->each->toBeInstanceOf(ChatbotAgent::class);
});

it('offers only agents whose groups are enabled', function () {
    $registry = registryOverAgents(registryForGroups(['files', 'server']));

    $available = $registry->availableFor(new TrustedToolContext());

    expect(array_keys($available))->toBe(['files', 'server']);
});

it('drops an agent whose every group is disabled', function () {
    $registry = registryOverAgents(registryForGroups(['server', 'files', 'startup', 'mods', 'subusers']));

    $available = $registry->availableFor(new TrustedToolContext());

    expect($available)->not->toHaveKey('power');
});

it('resolves an available agent by id', function () {
    $registry = registryOverAgents(registryForGroups(ChatbotToolGroup::defaults()));

    expect($registry->resolveFor(new TrustedToolContext(), 'files'))->toBeInstanceOf(FilesAgent::class);
});

it('returns null for an unknown agent id', function () {
    $registry = registryOverAgents(registryForGroups(ChatbotToolGroup::defaults()));

    expect($registry->resolveFor(new TrustedToolContext(), 'time_travel'))->toBeNull();
});

it('returns null for an id whose agent is not available', function () {
    $registry = registryOverAgents(registryForGroups(['files']));

    expect($registry->resolveFor(new TrustedToolContext(), 'power'))->toBeNull();
});

it('memoizes the available set per server and user', function () {
    $registry = registryOverAgents(registryForGroups(ChatbotToolGroup::defaults()), CountingFilesAgent::class);

    $context = new TrustedToolContext();

    $registry->availableFor($context);
    $registry->availableFor($context);

    expect(CountingFilesAgent::$calls)->toBe(1);

    $server = (new ReflectionClass(Server::class))->newInstanceWithoutConstructor();
    $server->id = 42;
    $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
    $user->id = 7;
    $context = new TrustedToolContext($server, $user);

    $registry->availableFor($context);

    expect(CountingFilesAgent::$calls)->toBe(2);
});
