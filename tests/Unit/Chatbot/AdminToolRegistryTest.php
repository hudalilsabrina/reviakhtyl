<?php

use App\Models\User;
use App\Services\Chatbot\AdminToolRegistry;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Admin\ListServersTool;

/**
 * The admin tool registry must gate on root admin status alone: no server
 * context exists, and the per-server group toggles must not be able to hide
 * admin tools (they are not part of the server chatbot's settings UI).
 *
 * The registry is built without its constructor (which would pull the
 * container in) and the tools without theirs, as in AgentRegistryTest.
 */
function adminReflectionSet(object $object, string $property, mixed $value): void
{
    // The tool list lives on the parent ToolRegistry, so the property must be
    // resolved from its declaring class, not from the subclass instance.
    $class = new ReflectionClass($object);

    while (! $class->hasProperty($property)) {
        $class = $class->getParentClass();
    }

    $property = $class->getProperty($property);
    $property->setValue($object, $value);
}

function adminReflectionBuild(string $class): object
{
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
}

function adminRegistryForTools(array $classes): AdminToolRegistry
{
    $tools = [];

    foreach ($classes as $class) {
        $tool = adminReflectionBuild($class);
        $tools[$tool->name()] = $tool;
    }

    $registry = adminReflectionBuild(AdminToolRegistry::class);
    adminReflectionSet($registry, 'tools', $tools);

    return $registry;
}

function adminToolUser(bool $root = true, int $id = 1): User
{
    $user = adminReflectionBuild(User::class);
    adminReflectionSet($user, 'attributes', ['root_admin' => $root, 'id' => $id]);

    return $user;
}

function adminToolContext(bool $root = true, int $id = 1): ToolContext
{
    return new ToolContext(null, adminToolUser($root, $id));
}

it('offers admin tools to a root admin with no server context', function () {
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $registry = adminRegistryForTools($config['admin_tools']);

    $tools = $registry->availableFor(adminToolContext(true));

    expect($tools)->toHaveKey('list_servers')
        ->and($tools['list_servers'])->toBeInstanceOf(ListServersTool::class)
        ->and(array_keys($tools))->toHaveCount(count($config['admin_tools']));
});

it('hides admin tools from non-root users', function () {
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $registry = adminRegistryForTools($config['admin_tools']);

    expect($registry->availableFor(adminToolContext(false)))->toBe([]);
});

it('resolves a tool by name for a root admin only', function () {
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $registry = adminRegistryForTools($config['admin_tools']);

    expect($registry->resolveFor(adminToolContext(true), 'delete_server'))->not->toBeNull()
        ->and($registry->resolveFor(adminToolContext(true), 'get_server_details'))->not->toBeNull()
        ->and($registry->resolveFor(adminToolContext(false, 2), 'delete_server'))->toBeNull();
});

it('does not leak server tools into the admin scope', function () {
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    $registry = adminRegistryForTools($config['admin_tools']);

    $tools = $registry->availableFor(adminToolContext(true));

    expect($tools)->not->toHaveKey('get_server_resources')
        ->and($tools)->not->toHaveKey('power_action')
        ->and($tools)->not->toHaveKey('read_file');
});
