<?php

use App\Exceptions\Service\Backup\TooManyBackupsException;
use App\Extensions\Backups\BackupManager;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Backups\DeleteBackupService;
use App\Services\Backups\InitiateBackupService;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

function makeInitiateService(array $overrides = []): InitiateBackupService
{
    $repository = $overrides['repository'] ?? Mockery::mock(BackupRepository::class);
    $connection = $overrides['connection'] ?? Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();
    $daemon = $overrides['daemon'] ?? Mockery::mock(DaemonBackupRepository::class);
    $delete = $overrides['delete'] ?? Mockery::mock(DeleteBackupService::class);
    $manager = $overrides['manager'] ?? Mockery::mock(BackupManager::class);

    return new InitiateBackupService($repository, $connection, $daemon, $delete, $manager);
}

function initiateFakeServer(int $backupLimit = 5): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $server->uuid = 'server-uuid';
    $server->backup_limit = $backupLimit;

    return $server;
}

function fakeCreatedBackup(): Backup
{
    $backup = new Backup();
    $backup->forceFill([
        'uuid' => 'created-backup-uuid',
        'name' => 'Backup at test',
        'ignored_files' => ['cache'],
        'is_locked' => false,
        'disk' => 'agent',
    ]);

    return $backup;
}

/**
 * A HasMany-shaped mock so the service's count/where/orderBy/first chain
 * runs without a database behind it.
 */
function fakeNonFailed(int $count, ?Backup $oldest = null): HasMany
{
    $nonFailed = Mockery::mock(HasMany::class);
    $nonFailed->shouldReceive('count')->andReturn($count);
    $nonFailed->shouldReceive('where')->with('is_locked', false)->andReturnSelf();
    $nonFailed->shouldReceive('orderBy')->with('created_at')->andReturnSelf();
    $nonFailed->shouldReceive('first')->andReturn($oldest);

    return $nonFailed;
}

function expectDaemonBackupCall(DaemonBackupRepository|MockInterface $daemon, Backup $backup): void
{
    $daemon->shouldReceive('setServer')->once()->andReturnSelf();
    $daemon->shouldReceive('setBackupAdapter')->once()->with('agent')->andReturnSelf();
    $daemon->shouldReceive('backup')->once()->withArgs(fn ($b) => $b === $backup)->andReturn(new Response(202));
}

beforeEach(function () {
    $this->manager = Mockery::mock(BackupManager::class);
    $this->manager->shouldReceive('getDefaultAdapter')->andReturn('agent');
});

afterEach(function () {
    Mockery::close();
});

it('creates a backup record and asks the daemon to generate it', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->andReturn(collect([]));
    $repository->shouldReceive('getNonFailedBackups')->andReturn(fakeNonFailed(0));
    $created = fakeCreatedBackup();
    $repository->shouldReceive('create')->once()
        ->withArgs(fn ($fields) => $fields['name'] === 'My Backup'
            && $fields['server_id'] === 1
            && $fields['disk'] === 'agent')
        ->andReturn($created);

    $server = initiateFakeServer();

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    expectDaemonBackupCall($daemon, $created);

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'manager' => $this->manager,
    ]);

    expect($service->handle($server, 'My Backup'))->toBe($created);
});

it('uses a generated name when no name is provided', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->andReturn(collect([]));
    $repository->shouldReceive('getNonFailedBackups')->andReturn(fakeNonFailed(0));
    $created = fakeCreatedBackup();
    $repository->shouldReceive('create')->once()
        ->withArgs(fn ($fields) => str_starts_with($fields['name'], 'Backup at '))
        ->andReturn($created);

    $server = initiateFakeServer();

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    expectDaemonBackupCall($daemon, $created);

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'manager' => $this->manager,
    ]);

    expect($service->handle($server))->toBe($created);
});

it('throws when the server has reached its backup limit without an override', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->andReturn(collect([]));
    $repository->shouldReceive('getNonFailedBackups')->andReturn(fakeNonFailed(2));
    $repository->shouldNotReceive('create');

    $server = initiateFakeServer(backupLimit: 2);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldNotReceive('setServer');

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'manager' => $this->manager,
    ]);

    expect(fn () => $service->handle($server, null, false))->toThrow(TooManyBackupsException::class);
});

it('deletes the oldest unlocked backup when overriding and then creates a new one', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->andReturn(collect([]));
    $oldest = Mockery::mock(Backup::class)->makePartial();
    $repository->shouldReceive('getNonFailedBackups')->andReturn(fakeNonFailed(2, $oldest));

    $delete = Mockery::mock(DeleteBackupService::class);
    $delete->shouldReceive('handle')->once()->with($oldest);

    $created = fakeCreatedBackup();
    $repository->shouldReceive('create')->once()->andReturn($created);

    $server = initiateFakeServer(backupLimit: 2);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    expectDaemonBackupCall($daemon, $created);

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'delete' => $delete,
        'manager' => $this->manager,
    ]);

    $backup = $service->handle($server, null, true);

    expect($backup)->toBe($created);
});

it('throws when overriding but every backup is locked', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->andReturn(collect([]));
    $repository->shouldReceive('getNonFailedBackups')->andReturn(fakeNonFailed(2, null));
    $repository->shouldNotReceive('create');

    $server = initiateFakeServer(backupLimit: 2);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldNotReceive('setServer');

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'manager' => $this->manager,
    ]);

    expect(fn () => $service->handle($server, null, true))->toThrow(TooManyBackupsException::class);
});

it('throws a TooManyRequestsHttpException when the throttle is exceeded', function () {
    $repository = Mockery::mock(BackupRepository::class);
    $previous = collect([
        (new Backup())->forceFill(['uuid' => 'a', 'created_at' => CarbonImmutable::now()->subMinutes(5)]),
        (new Backup())->forceFill(['uuid' => 'b', 'created_at' => CarbonImmutable::now()->subMinutes(4)]),
    ]);
    $repository->shouldReceive('getBackupsGeneratedDuringTimespan')->once()->andReturn($previous);
    $repository->shouldNotReceive('getNonFailedBackups');
    $repository->shouldNotReceive('create');

    $server = initiateFakeServer();

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldNotReceive('setServer');

    $service = makeInitiateService([
        'repository' => $repository,
        'daemon' => $daemon,
        'manager' => $this->manager,
    ]);

    expect(fn () => $service->handle($server))->toThrow(TooManyRequestsHttpException::class);
});
