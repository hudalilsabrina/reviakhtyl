<?php

use App\Http\Requests\Api\Client\Servers\Schedules\StoreScheduleRequest;
use App\Models\Schedule;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Cron\CronExpression;

it('validates the month cron field on schedule create and update', function () {
    $request = new StoreScheduleRequest();
    $rules = $request->rules();

    expect($rules)->toHaveKey('month');

    // Validates against the model rule for cron_month (required string).
    $ruleSet = $rules['month'];
    expect($ruleSet)->toBe(Schedule::getRules()['cron_month']);

    $validator = validator(['month' => '2'], ['month' => $ruleSet]);
    expect($validator->passes())->toBeTrue();

    $validator = validator([], ['month' => $ruleSet]);
    expect($validator->passes())->toBeFalse();
});

it('maps task actions to the correct subuser permissions', function () {
    expect(Task::permissionForAction(Task::ACTION_COMMAND))->toBe('control.console')
        ->and(Task::permissionForAction(Task::ACTION_BACKUP))->toBe('backup.create')
        ->and(Task::permissionForAction(Task::ACTION_POWER, 'start'))->toBe('control.start')
        ->and(Task::permissionForAction(Task::ACTION_POWER, 'restart'))->toBe('control.restart')
        ->and(Task::permissionForAction(Task::ACTION_POWER, 'stop'))->toBe('control.stop')
        ->and(Task::permissionForAction(Task::ACTION_POWER, 'kill'))->toBe('control.stop');
});

it('returns null for unknown actions or power payloads', function () {
    expect(Task::permissionForAction('foo'))->toBeNull()
        ->and(Task::permissionForAction(Task::ACTION_POWER, 'bogus'))->toBeNull()
        ->and(Task::permissionForAction(Task::ACTION_POWER))->toBeNull()
        ->and(Task::permissionForAction(''))->toBeNull();
});

it('computes the next run date from the five stored cron columns', function () {
    // The stored columns are in cron order: minute hour day-of-month month day-of-week.
    $schedule = new Schedule(['server_id' => 1]);
    $schedule->setAttribute('cron_minute', '30');
    $schedule->setAttribute('cron_hour', '4');
    $schedule->setAttribute('cron_day_of_month', '1');
    $schedule->setAttribute('cron_month', '2');
    $schedule->setAttribute('cron_day_of_week', '*');

    $next = $schedule->getNextRunDate();

    // Same expression evaluated directly — guards against a column ordering
    // regression that would silently fire at the wrong time.
    $expected = CarbonImmutable::instance(
        (new CronExpression('30 4 1 2 *'))->getNextRunDate()
    );

    expect($next->equalTo($expected))->toBeTrue();
});
