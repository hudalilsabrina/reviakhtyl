<?php

use App\Models\Backup;
use App\Models\Permission;
use App\Models\Server;
use App\Models\User;
use App\Services\Backups\InitiateBackupService;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Backups\CreateBackupTool;
use Illuminate\Support\Facades\Gate;
use Mockery\MockInterface;

function makeCreateTool(InitiateBackupService|MockInterface $service): CreateBackupTool
{
    return new CreateBackupTool($service);
}

function fakeCreateServer(): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $server->uuid = 'server-uuid';

    return $server;
}

/**
 * Mocks the Gate facade so ToolContext::can() answers from the permission map
 * without touching the database.
 */
function stubToolPermission(User $user, Server $server, string $permission, bool $allowed): void
{
    $gate = Mockery::mock(Illuminate\Contracts\Auth\Access\Gate::class);
    $gate->shouldReceive('allows')->with($permission, $server)->andReturn($allowed);
    Gate::shouldReceive('forUser')->with($user)->andReturn($gate);
}

function createdBackupResult(string $uuid = 'backup-uuid'): Backup
{
    $backup = new Backup();
    $backup->forceFill([
        'uuid' => $uuid,
        'name' => 'My Backup',
        'ignored_files' => [],
        'is_locked' => false,
        'disk' => 'agent',
    ]);

    return $backup;
}

afterEach(function () {
    Mockery::close();
});

it('locks the backup when the user can also delete backups', function () {
    $server = fakeCreateServer();
    $user = new User();
    stubToolPermission($user, $server, Permission::ACTION_BACKUP_DELETE, true);

    $created = createdBackupResult();
    $service = Mockery::mock(InitiateBackupService::class);
    $service->shouldReceive('setIsLocked')->with(true)->once()->andReturnSelf();
    $service->shouldReceive('setIgnoredFiles')->with(['cache'])->once()->andReturnSelf();
    $service->shouldReceive('handle')->once()->with($server, 'My Backup')->andReturn($created);

    $tool = makeCreateTool($service);

    $result = $tool->handle(new ToolContext($server, $user), [
        'name' => 'My Backup',
        'ignore_files' => ['cache'],
        'is_locked' => true,
    ]);

    expect($result['uuid'])->toBe('backup-uuid')
        ->and($result['is_locked'])->toBe(false);
});

it('ignores the lock request when the user cannot delete backups', function () {
    $server = fakeCreateServer();
    $user = new User();
    stubToolPermission($user, $server, Permission::ACTION_BACKUP_DELETE, false);

    $created = createdBackupResult();
    $service = Mockery::mock(InitiateBackupService::class);
    $service->shouldReceive('setIsLocked')->with(false)->once()->andReturnSelf();
    $service->shouldReceive('setIgnoredFiles')->with(null)->once()->andReturnSelf();
    $service->shouldReceive('handle')->once()->with($server, null)->andReturn($created);

    $tool = makeCreateTool($service);

    $result = $tool->handle(new ToolContext($server, $user), ['is_locked' => true]);

    expect($result['is_locked'])->toBe(false);
});
