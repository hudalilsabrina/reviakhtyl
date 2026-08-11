<?php

use App\Contracts\Repository\EggVariableRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Exceptions\Service\Egg\Variable\BadValidationRuleException;
use App\Exceptions\Service\Egg\Variable\ReservedVariableNameException;
use App\Models\EggVariable;
use App\Services\Eggs\Variables\VariableUpdateService;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

function makeVariableUpdateService(array $overrides = []): VariableUpdateService
{
    $repo = $overrides['repo'] ?? Mockery::mock(EggVariableRepositoryInterface::class);
    $validator = $overrides['validator'] ?? app(ValidationFactory::class);

    return new VariableUpdateService($repo, $validator);
}

function makeEggVariable(array $attributes = []): EggVariable
{
    $variable = Mockery::mock(EggVariable::class);
    $variable->shouldReceive('getAttribute')->with('egg_id')->andReturn($attributes['egg_id'] ?? 1);
    $variable->shouldReceive('getAttribute')->with('id')->andReturn($attributes['id'] ?? 5);

    return $variable;
}

it('rejects a reserved environment variable name', function () {
    $variable = makeEggVariable();

    $service = makeVariableUpdateService();

    expect(fn () => $service->handle($variable, ['env_variable' => 'SERVER_PORT']))
        ->toThrow(ReservedVariableNameException::class, 'protected and cannot be assigned');
});

it('rejects a duplicate environment variable within the same egg', function () {
    $variable = makeEggVariable(['egg_id' => 1, 'id' => 5]);

    $repo = Mockery::mock(EggVariableRepositoryInterface::class);
    $repo->shouldReceive('setColumns')->with('id')->once()->andReturnSelf();
    $repo->shouldReceive('findCountWhere')
        ->once()
        ->with([['env_variable', '=', 'DUPLICATE'], ['egg_id', '=', 1], ['id', '!=', 5]])
        ->andReturn(1);

    $service = makeVariableUpdateService(['repo' => $repo]);

    expect(fn () => $service->handle($variable, ['env_variable' => 'DUPLICATE']))
        ->toThrow(DisplayException::class, 'must be unique to this Egg');
});

it('rejects an invalid validation rule', function () {
    $variable = makeEggVariable();

    $service = makeVariableUpdateService();

    expect(fn () => $service->handle($variable, ['rules' => 'notarealrule']))
        ->toThrow(BadValidationRuleException::class);
});

it('rejects an unparsable regex rule', function () {
    $variable = makeEggVariable();

    $service = makeVariableUpdateService();

    expect(fn () => $service->handle($variable, ['rules' => 'regex:/[unclosed/']))
        ->toThrow(BadValidationRuleException::class);
});

it('updates the variable fields and normalizes option flags', function () {
    $variable = makeEggVariable(['egg_id' => 3, 'id' => 9]);

    $repo = Mockery::mock(EggVariableRepositoryInterface::class);
    $repo->shouldReceive('setColumns')->with('id')->once()->andReturnSelf();
    $repo->shouldReceive('findCountWhere')->once()->andReturn(0);
    $repo->shouldReceive('withoutFreshModel')->once()->andReturnSelf();
    $repo->shouldReceive('update')->once()->with(9, Mockery::on(function (array $data) {
        return $data['name'] === 'New Name'
            && $data['env_variable'] === 'NEW_VAR'
            && $data['default_value'] === 'val'
            && $data['user_viewable'] === true
            && $data['user_editable'] === false
            && $data['rules'] === 'required|string';
    }))->andReturn(true);

    $service = makeVariableUpdateService(['repo' => $repo]);

    $result = $service->handle($variable, [
        'name' => 'New Name',
        'env_variable' => 'NEW_VAR',
        'default_value' => 'val',
        'options' => ['user_viewable'],
        'rules' => 'required|string',
    ]);

    expect($result)->toBe(true);
});

it('validates rules split on double-semicolons like the admin UI sends', function () {
    $variable = makeEggVariable();

    $repo = Mockery::mock(EggVariableRepositoryInterface::class);
    $repo->shouldReceive('setColumns')->with('id')->once()->andReturnSelf();
    $repo->shouldReceive('findCountWhere')->once()->andReturn(0);
    $repo->shouldReceive('withoutFreshModel')->once()->andReturnSelf();
    $repo->shouldReceive('update')->once()->andReturn(true);

    $service = makeVariableUpdateService(['repo' => $repo]);

    // "required;;string" arrives as a single string; must be exploded before rule validation.
    $service->handle($variable, ['env_variable' => 'NEW_VAR', 'rules' => 'required;;string']);

    expect(true)->toBeTrue();
});
