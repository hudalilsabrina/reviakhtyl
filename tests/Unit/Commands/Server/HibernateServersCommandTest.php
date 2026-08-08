<?php

namespace Tests\Unit\Commands\Server;

use App\Console\Commands\Server\HibernateServersCommand;

it('hibernates when the average CPU stays below the threshold across the full window', function (): void {
    expect(HibernateServersCommand::shouldHibernate([0.1, 0.2, 0.5], 5, 3))->toBeTrue();
});

it('does not hibernate when the average CPU meets or exceeds the threshold', function (): void {
    expect(HibernateServersCommand::shouldHibernate([0.1, 15.0, 0.5], 5, 3))->toBeFalse();
    expect(HibernateServersCommand::shouldHibernate([5.0, 5.0, 5.0], 5, 3))->toBeFalse();
});

it('does not hibernate when there is not enough snapshot data in the window', function (): void {
    expect(HibernateServersCommand::shouldHibernate([0.1, 0.2], 5, 3))->toBeFalse();
});

it('hibernates a single snapshot when only one is required', function (): void {
    expect(HibernateServersCommand::shouldHibernate([0.1], 5, 1))->toBeTrue();
});
