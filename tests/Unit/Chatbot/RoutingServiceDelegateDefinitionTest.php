<?php

use App\Services\Chatbot\RoutingService;

/**
 * The router's only tool definition is exercised here, built without the
 * service's constructor: delegateDefinition() touches nothing but its own
 * array.
 */
function routingService(): RoutingService
{
    return (new ReflectionClass(RoutingService::class))->newInstanceWithoutConstructor();
}

it('declares a JSON encodable delegate definition', function () {
    $definition = routingService()->delegateDefinition();

    expect(json_encode($definition, JSON_THROW_ON_ERROR))->toBeString();
});

it('describes the router tool with the required shape', function () {
    $definition = routingService()->delegateDefinition();

    expect($definition['type'])->toBe('function')
        ->and($definition['function']['name'])->toBe('delegate')
        ->and($definition['function']['description'])->toBeString()->not->toBe('')
        ->and($definition['function']['parameters']['type'])->toBe('object')
        ->and($definition['function']['parameters'])->toHaveKey('properties')
        ->and($definition['function']['parameters']['required'])->toBe(['request', 'to_agent_ids'])
        ->and($definition['function']['parameters']['additionalProperties'])->toBeFalse();
});

it('describes every declared property of the delegate tool', function () {
    $parameters = routingService()->delegateDefinition()['function']['parameters'];

    foreach ($parameters['properties'] as $name => $schema) {
        expect($schema)->toBeArray()
            ->toHaveKey('type')
            ->toHaveKey('description');
    }

    expect($parameters['properties'])->toHaveKey('request')
        ->toHaveKey('to_agent_ids')
        ->toHaveKey('context_budget');
});

it('never gives the router a panel tool', function () {
    // The router's sole definition is the delegate tool, so it is incapable of
    // performing a side effect itself.
    $definition = routingService()->delegateDefinition();

    expect($definition['function']['name'])->toBe('delegate');
});

it('offers answer_directly alongside delegate as the classifier tool', function () {
    $definitions = routingService()->definitions();

    expect(array_map(fn (array $d) => $d['function']['name'], $definitions))
        ->toBe(['delegate', 'answer_directly']);
});

it('describes the answer_directly tool with no parameters', function () {
    $definition = routingService()->answerDirectlyDefinition();

    expect($definition['type'])->toBe('function')
        ->and($definition['function']['name'])->toBe('answer_directly')
        ->and($definition['function']['description'])->toBeString()->not->toBe('')
        ->and($definition['function']['parameters']['type'])->toBe('object')
        ->and($definition['function']['parameters']['additionalProperties'])->toBeFalse()
        ->and(json_encode($definition, JSON_THROW_ON_ERROR))->toBeString();
});
