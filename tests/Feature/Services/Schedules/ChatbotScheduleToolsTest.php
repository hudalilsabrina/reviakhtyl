<?php

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Permission;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Models\User;
use App\Repositories\Eloquent\ScheduleRepository;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Schedules\CreateScheduleTool;
use App\Services\Chatbot\Tools\Schedules\ExecuteScheduleTool;
use App\Services\Chatbot\Tools\Schedules\ListSchedulesTool;
use App\Services\Schedules\ProcessScheduleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

afterEach(function () {
    Mockery::close();
});

/**
 * A ToolContext whose can() grants exactly the given permissions.
 */
function contextFor(array $permissions): ToolContext
{
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $user = Mockery::mock(User::class);

    $context = Mockery::mock(ToolContext::class)->makePartial();
    $context->__construct($server, $user);
    $context->shouldReceive('can')->andReturnUsing(
        fn (string $permission) => in_array($permission, $permissions, true)
    );

    return $context;
}

/**
 * Builds an in-memory Schedule with tasks loaded on the relation.
 *
 * @param  array<string, mixed>  $attributes
 * @param  array<int, array<string, mixed>>  $taskAttributes
 */
function scheduleWithTasks(array $attributes = [], array $taskAttributes = []): Schedule
{
    $schedule = new Schedule();
    foreach (array_merge([
        'id' => 7,
        'server_id' => 1,
        'name' => 'nightly',
        'cron_minute' => '30',
        'cron_hour' => '4',
        'cron_day_of_month' => '1',
        'cron_month' => '2',
        'cron_day_of_week' => '*',
    ], $attributes) as $key => $value) {
        $schedule->setAttribute($key, $value);
    }

    $tasks = [];
    foreach ($taskAttributes as $i => $taskData) {
        $task = new Task();
        foreach (array_merge([
            'id' => $i + 1,
            'schedule_id' => $schedule->id,
            'sequence_id' => $i + 1,
            'action' => 'command',
            'payload' => 'say hi',
            'time_offset' => 0,
            'is_queued' => false,
            'continue_on_failure' => false,
        ], $taskData) as $key => $value) {
            $task->setAttribute($key, $value);
        }
        $tasks[] = $task;
    }
    $schedule->setRelation('tasks', collect($tasks));

    return $schedule;
}

describe('CreateScheduleTool', function () {
    it('rejects schedules over the per-schedule task limit', function () {
        config(['panel.client_features.schedules.per_schedule_task_limit' => 2]);

        $tool = new CreateScheduleTool();
        $context = contextFor([
            Permission::ACTION_SCHEDULE_CREATE,
            Permission::ACTION_CONTROL_CONSOLE,
        ]);

        expect(fn () => $tool->handle($context, [
            'name' => 'too many',
            'cron' => '0 3 * * *',
            'tasks' => [
                ['action' => 'command', 'payload' => 'a'],
                ['action' => 'command', 'payload' => 'b'],
                ['action' => 'command', 'payload' => 'c'],
            ],
        ]))->toThrow(ChatbotException::class, 'more than 2 tasks');
    });

    it('rejects a task action the user lacks permission for', function () {
        $tool = new CreateScheduleTool();
        $context = contextFor([Permission::ACTION_SCHEDULE_CREATE]); // no control.console

        expect(fn () => $tool->handle($context, [
            'name' => 'no perms',
            'cron' => '0 3 * * *',
            'tasks' => [
                ['action' => 'command', 'payload' => 'say hi'],
            ],
        ]))->toThrow(ChatbotException::class, 'permission');
    });

    it('requires control.start for a power task', function () {
        $tool = new CreateScheduleTool();
        $context = contextFor([
            Permission::ACTION_SCHEDULE_CREATE,
            Permission::ACTION_CONTROL_CONSOLE,
            Permission::ACTION_CONTROL_START,
        ]);

        // Would proceed to DB writes; we only assert no ChatbotException for
        // the permission gate itself, so use a cron that passes and an
        // impossible-to-reach task count check.
        expect(fn () => $tool->handle($context, [
            'name' => 'start only',
            'cron' => '0 3 * * *',
            'tasks' => [
                ['action' => 'power', 'payload' => 'start'],
            ],
        ]))->not->toThrow(ChatbotException::class);

        $denied = contextFor([Permission::ACTION_SCHEDULE_CREATE]);
        expect(fn () => $tool->handle($denied, [
            'name' => 'start only',
            'cron' => '0 3 * * *',
            'tasks' => [
                ['action' => 'power', 'payload' => 'start'],
            ],
        ]))->toThrow(ChatbotException::class, 'permission');
    });
});

describe('ListSchedulesTool', function () {
    it('reports the cron expression in standard 5-field order', function () {
        $schedule = scheduleWithTasks([], [
            ['action' => 'command', 'payload' => 'say hi'],
        ]);

        // schedules()->with('tasks')->get() — return the collection of one.
        $relation = Mockery::mock(HasMany::class);
        $relation->shouldReceive('with')->with('tasks')->andReturnSelf();
        $relation->shouldReceive('get')->andReturn(collect([$schedule]));

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $server->shouldReceive('schedules')->andReturn($relation);

        $user = Mockery::mock(User::class);
        $context = Mockery::mock(ToolContext::class)->makePartial();
        $context->__construct($server, $user);

        $tool = new ListSchedulesTool();

        $result = $tool->handle($context, []);

        expect($result['entries'][0]['cron'])->toBe('30 4 1 2 *');
    });
});

describe('ExecuteScheduleTool', function () {
    function makeExecuteTool(Schedule $schedule, array $granted): array
    {
        $repo = Mockery::mock(ScheduleRepository::class);
        $builder = Mockery::mock(Builder::class);
        $builder->shouldReceive('where')->with('server_id', 1)->andReturnSelf();
        $builder->shouldReceive('where')->with('id', 7)->andReturnSelf();
        $builder->shouldReceive('first')->andReturn($schedule);
        $repo->shouldReceive('getBuilder')->andReturn($builder);

        $process = Mockery::mock(ProcessScheduleService::class);

        $server = Mockery::mock(Server::class);
        $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $user = Mockery::mock(User::class);
        $context = Mockery::mock(ToolContext::class)->makePartial();
        $context->__construct($server, $user);
        $context->shouldReceive('can')->andReturnUsing(
            fn (string $permission) => in_array($permission, $granted, true)
        );

        return [new ExecuteScheduleTool($repo, $process), $process, $context];
    }

    it('executes when the user holds every task action permission', function () {
        $schedule = scheduleWithTasks([], [
            ['action' => 'command', 'payload' => 'say hi'],
            ['action' => 'power', 'payload' => 'restart'],
        ]);

        [$tool, $process, $context] = makeExecuteTool($schedule, [
            Permission::ACTION_SCHEDULE_UPDATE,
            Permission::ACTION_CONTROL_CONSOLE,
            Permission::ACTION_CONTROL_RESTART,
        ]);

        $process->shouldReceive('handle')->once()->with($schedule, true);

        $result = $tool->handle($context, ['schedule_id' => 7]);

        expect($result['schedule_id'])->toBe(7);
    });

    it('denies execution when the user lacks a task action permission', function () {
        $schedule = scheduleWithTasks([], [
            ['action' => 'command', 'payload' => 'say hi'],
            ['action' => 'power', 'payload' => 'restart'],
        ]);

        // Has schedule.update and control.console, but not control.restart.
        [$tool, $process, $context] = makeExecuteTool($schedule, [
            Permission::ACTION_SCHEDULE_UPDATE,
            Permission::ACTION_CONTROL_CONSOLE,
        ]);

        $process->shouldNotReceive('handle');

        expect(fn () => $tool->handle($context, ['schedule_id' => 7]))
            ->toThrow(ChatbotException::class, 'permission');
    });

    it('denies execution when a power payload maps to no permission', function () {
        $schedule = scheduleWithTasks([], [
            ['action' => 'power', 'payload' => 'bogus'],
        ]);

        [$tool, $process, $context] = makeExecuteTool($schedule, [
            Permission::ACTION_SCHEDULE_UPDATE,
        ]);

        $process->shouldNotReceive('handle');

        expect(fn () => $tool->handle($context, ['schedule_id' => 7]))
            ->toThrow(ChatbotException::class, 'permission');
    });
});
