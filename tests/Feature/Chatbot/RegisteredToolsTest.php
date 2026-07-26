<?php

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\Tools\ChatbotTool;

/**
 * Every tool listed in config/chatbot.php is contract-checked here. The list is
 * read from the file directly rather than through config(), because Pest
 * resolves datasets before the application exists.
 *
 * @return string[]
 */
function registeredChatbotTools(): array
{
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    return array_values($config['tools']);
}

dataset('chatbot tools', array_combine(
    registeredChatbotTools(),
    array_map(fn (string $class) => [$class], registeredChatbotTools()),
));

it('resolves from the container', function (string $class) {
    expect(app($class))->toBeInstanceOf(ChatbotTool::class);
})->with('chatbot tools');

it('has a name the provider will accept', function (string $class) {
    $name = app($class)->name();

    expect($name)->toBeString()
        ->and($name)->toMatch('/^[a-zA-Z0-9_-]{1,64}$/');
})->with('chatbot tools');

it('has a description the model can act on', function (string $class) {
    $description = app($class)->description();

    expect($description)->toBeString()
        ->and(strlen($description))->toBeGreaterThan(40);
})->with('chatbot tools');

it('belongs to a tool group', function (string $class) {
    expect(app($class)->group())->toBeInstanceOf(ChatbotToolGroup::class);
})->with('chatbot tools');

it('declares a JSON encodable definition', function (string $class) {
    $tool = app($class);
    $definition = $tool->definition();

    $encoded = json_encode($definition, JSON_THROW_ON_ERROR);

    expect($encoded)->toBeString()
        ->and($definition['type'])->toBe('function')
        ->and($definition['function']['name'])->toBe($tool->name())
        ->and($definition['function']['description'])->toBe($tool->description())
        ->and(json_encode($definition['function']['parameters']))->toBe(json_encode($tool->parameters()));
})->with('chatbot tools');

it('declares an object parameter schema', function (string $class) {
    $parameters = app($class)->parameters();

    expect($parameters)->toBeArray()
        ->and($parameters['type'] ?? null)->toBe('object')
        ->and($parameters)->toHaveKey('properties');
})->with('chatbot tools');

it('only requires properties it actually declares', function (string $class) {
    $parameters = app($class)->parameters();
    $properties = (array) ($parameters['properties'] ?? []);

    foreach ($parameters['required'] ?? [] as $required) {
        expect($properties)->toHaveKey($required);
    }

    // A tool with no required arguments is fine; the loop above just has
    // nothing to check, so record the shape instead.
    expect($parameters['required'] ?? [])->toBeArray();
})->with('chatbot tools');

it('describes every declared property', function (string $class) {
    $properties = (array) (app($class)->parameters()['properties'] ?? []);

    foreach ($properties as $name => $schema) {
        expect($schema)->toBeArray("Property \"$name\" must be a schema array.")
            ->and($schema)->toHaveKey('type');
    }

    expect(true)->toBeTrue();
})->with('chatbot tools');

it('summarizes an empty argument list without throwing', function (string $class) {
    $summary = app($class)->summarize([]);

    expect($summary)->toBeString()->not->toBe('');
})->with('chatbot tools');

it('summarizes its own declared arguments without throwing', function (string $class) {
    $tool = app($class);
    $properties = (array) ($tool->parameters()['properties'] ?? []);

    $arguments = [];

    foreach ($properties as $name => $schema) {
        $arguments[$name] = match ($schema['type'] ?? 'string') {
            'array' => ['a', 'b'],
            'object' => ['k' => 'v'],
            'integer', 'number' => 7,
            'boolean' => true,
            default => 'value',
        };
    }

    expect($tool->summarize($arguments))->toBeString()->not->toBe('');
})->with('chatbot tools');

it('declares permissions as a list of strings', function (string $class) {
    $permissions = app($class)->permissions();

    expect($permissions)->toBeArray();

    foreach ($permissions as $permission) {
        expect($permission)->toBeString()->not->toBe('');
    }

    expect(array_is_list($permissions))->toBeTrue();
})->with('chatbot tools');

it('gives every registered tool a unique name', function () {
    $names = array_map(fn (string $class) => app($class)->name(), registeredChatbotTools());

    $duplicates = array_keys(array_filter(array_count_values($names), fn (int $count) => $count > 1));

    expect($duplicates)->toBe([], 'Duplicate tool names: '.implode(', ', $duplicates))
        ->and($names)->toHaveCount(count(registeredChatbotTools()));
});

it('registers the same set of tools in the registry as in the config file', function () {
    expect(config('chatbot.tools'))->toBe(registeredChatbotTools());
});

it('marks the destructive tools as destructive', function () {
    $destructive = [];

    foreach (registeredChatbotTools() as $class) {
        $tool = app($class);

        if ($tool->isDestructive()) {
            $destructive[] = $tool->name();
        }
    }

    // Deleting files and removing subusers are irreversible from the panel, so
    // they must always sit behind the confirmation prompt.
    expect($destructive)->toContain('delete_files')
        ->toContain('delete_subuser');
});
