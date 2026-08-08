<?php

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\Agents\ChatbotAgent;

/**
 * Every agent listed in config/chatbot.php is contract-checked here. The list
 * is read from the file directly rather than through config(), because Pest
 * resolves datasets before the application exists.
 *
 * @return string[]
 */
function registeredChatbotAgents(): array
{
    $config = require dirname(__DIR__, 3).'/config/chatbot.php';

    return array_values($config['agents']);
}

dataset('chatbot agents', array_combine(
    registeredChatbotAgents(),
    array_map(fn (string $class) => [$class], registeredChatbotAgents()),
));

it('resolves from the container', function (string $class) {
    expect(app($class))->toBeInstanceOf(ChatbotAgent::class);
})->with('chatbot agents');

it('has an id the router will accept', function (string $class) {
    $id = app($class)->id();

    expect($id)->toBeString()
        ->and($id)->toMatch('/^[a-zA-Z0-9_-]{1,64}$/');
})->with('chatbot agents');

it('has a display name', function (string $class) {
    $name = app($class)->name();

    expect($name)->toBeString()
        ->and($name)->not->toBe('');
})->with('chatbot agents');

it('has a role directive the model can act on', function (string $class) {
    $directive = app($class)->systemDirective();

    expect($directive)->toBeString()
        ->and(strlen($directive))->toBeGreaterThan(40);
})->with('chatbot agents');

it('scopes itself to at least one real tool group', function (string $class) {
    $groups = app($class)->toolGroups();

    expect($groups)->toBeArray()->not->toBeEmpty();

    foreach ($groups as $group) {
        expect($group)->toBeInstanceOf(ChatbotToolGroup::class);
    }
})->with('chatbot agents');

it('gives every registered agent a unique id', function () {
    $ids = array_map(fn (string $class) => app($class)->id(), registeredChatbotAgents());

    $duplicates = array_keys(array_filter(array_count_values($ids), fn (int $count) => $count > 1));

    expect($duplicates)->toBe([], 'Duplicate agent ids: '.implode(', ', $duplicates))
        ->and($ids)->toHaveCount(count(registeredChatbotAgents()));
});

it('registers the same set of agents in the registry as in the config file', function () {
    expect(config('chatbot.agents'))->toBe(registeredChatbotAgents());
});

it('defaults every agent to the panel model', function (string $class) {
    expect(app($class)->model())->toBeNull();
})->with('chatbot agents');
