<?php

use App\Exceptions\DisplayException;
use App\Jobs\Schedule\RunTaskJob;
use App\Models\Schedule;
use App\Models\Server;
use App\Models\Task;
use App\Repositories\Agent\DaemonServerRepository;
use App\Services\Schedules\ProcessScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Builds a ProcessScheduleService with a fake connection (runs the closure
 * directly, no DB) and a fake dispatcher that records dispatched jobs.
 */
function makeProcessService(array $overrides = []): array
{
    $connection = $overrides['connection'] ?? Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();

    $dispatcher = $overrides['dispatcher'] ?? Mockery::mock(Dispatcher::class);
    $serverRepo = $overrides['server_repo'] ?? Mockery::mock(DaemonServerRepository::class);

    $service = new ProcessScheduleService($connection, $dispatcher, $serverRepo);

    return [$service, $dispatcher];
}

/**
 * Builds a partial-mock Schedule: instance methods run their real Eloquent
 * implementations, but forceFill/saveOrFail/tasks() are intercepted so no DB
 * query and no static Eloquent call ever runs.
 *
 * @param  Task[]  $tasks
 */
function schedulePartial(array $attributes = [], array $tasks = [], $server = null): Schedule
{
    $schedule = Mockery::mock(Schedule::class)->makePartial();
    foreach (array_merge([
        'server_id' => 1,
        'name' => 'test',
        'cron_minute' => '*',
        'cron_hour' => '*',
        'cron_day_of_month' => '*',
        'cron_month' => '*',
        'cron_day_of_week' => '*',
        'only_when_online' => false,
    ], $attributes) as $key => $value) {
        $schedule->setAttribute($key, $value);
    }

    $schedule->shouldReceive('forceFill')->andReturnUsing(function (array $data) use ($schedule) {
        foreach ($data as $key => $value) {
            $schedule->setAttribute($key, $value);
        }

        return $schedule;
    });
    $schedule->shouldReceive('saveOrFail')->andReturnTrue();
    $schedule->shouldReceive('getNextRunDate')->andReturn(CarbonImmutable::now()->addMinute());

    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('getModel')->andReturn(new Task());
    $builder->shouldReceive('orderBy')->with('sequence_id')->andReturnSelf();
    $builder->shouldReceive('first')->andReturn($tasks[0] ?? null);

    $schedule->shouldReceive('tasks')->andReturn(
        Relation::noConstraints(fn () => new HasMany($builder, $schedule, 'schedule_id', 'id'))
    );

    if ($server) {
        $schedule->setRelation('server', $server);
    }

    return $schedule;
}

/**
 * Builds a partial-mock Task whose update() and schedule() relation are no-ops.
 */
function taskPartial(array $attributes = []): Task
{
    $task = Mockery::mock(Task::class)->makePartial();
    foreach (array_merge([
        'schedule_id' => 1,
        'sequence_id' => 1,
        'action' => 'command',
        'payload' => 'say hi',
        'time_offset' => 0,
        'is_queued' => false,
    ], $attributes) as $key => $value) {
        $task->setAttribute($key, $value);
    }
    $task->shouldReceive('update')->andReturnTrue();

    // RunTaskJob::markScheduleComplete() calls $task->schedule()->update(...).
    $scheduleRelation = Mockery::mock(BelongsTo::class);
    $scheduleRelation->shouldReceive('update')->andReturnTrue();
    $task->shouldReceive('schedule')->andReturn($scheduleRelation);

    return $task;
}

it('throws when the schedule has no tasks', function () {
    $schedule = schedulePartial(['name' => 'empty'], []);
    [$service] = makeProcessService();

    expect(fn () => $service->handle($schedule))
        ->toThrow(DisplayException::class, 'no tasks are registered');
});

it('marks the schedule processing and queues the first task', function () {
    $task = taskPartial(['sequence_id' => 1, 'action' => 'command', 'time_offset' => 0]);
    $schedule = schedulePartial([], [$task]);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(fn ($job) => $job instanceof RunTaskJob && $job->manualRun === false))
        ->andReturnNull();

    [$service] = makeProcessService(['dispatcher' => $dispatcher]);

    $service->handle($schedule);

    $task->shouldHaveReceived('update')->with(['is_queued' => true])->once();
});

it('runs the first task immediately for manual execution', function () {
    $task = taskPartial(['sequence_id' => 1, 'action' => 'power', 'payload' => 'start', 'time_offset' => 120]);
    $schedule = schedulePartial([], [$task]);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatchNow')
        ->once()
        ->with(Mockery::on(fn (RunTaskJob $job) => $job->manualRun === true))
        ->andReturnNull();

    [$service] = makeProcessService(['dispatcher' => $dispatcher]);

    $service->handle($schedule, true);

    $task->shouldHaveReceived('update')->with(['is_queued' => true])->once();
});

it('does not dispatch when the server is offline and only_when_online is set', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);

    $task = taskPartial(['sequence_id' => 1, 'action' => 'command']);
    $schedule = schedulePartial(['only_when_online' => true], [$task], $server);

    $serverRepo = Mockery::mock(DaemonServerRepository::class);
    $serverRepo->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
    $serverRepo->shouldReceive('getDetails')->once()->andReturn(['state' => 'offline']);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldNotReceive('dispatch');
    $dispatcher->shouldNotReceive('dispatchNow');

    [$service] = makeProcessService(['dispatcher' => $dispatcher, 'server_repo' => $serverRepo]);

    $service->handle($schedule);

    // Offline means the job is failed: the task is un-queued and the schedule
    // is completed without running anything.
    $task->shouldHaveReceived('update')->with(['is_queued' => false])->once();
});

it('dispatches when the server is running and only_when_online is set', function () {
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);

    $task = taskPartial(['sequence_id' => 1, 'action' => 'command']);
    $schedule = schedulePartial(['only_when_online' => true], [$task], $server);

    $serverRepo = Mockery::mock(DaemonServerRepository::class);
    $serverRepo->shouldReceive('setServer')->once()->with($server)->andReturnSelf();
    $serverRepo->shouldReceive('getDetails')->once()->andReturn(['state' => 'running']);

    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->once()
        ->with(Mockery::on(fn ($job) => $job instanceof RunTaskJob))
        ->andReturnNull();

    [$service] = makeProcessService(['dispatcher' => $dispatcher, 'server_repo' => $serverRepo]);

    $service->handle($schedule);
});
