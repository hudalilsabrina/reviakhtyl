<?php

use App\Exceptions\Http\Connection\DaemonConnectionException;
use App\Exceptions\Service\Backup\BackupLockedException;
use App\Extensions\Backups\BackupManager;
use App\Extensions\Filesystem\S3Filesystem;
use App\Models\Backup;
use App\Models\Server;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Services\Backups\DeleteBackupService;
use Aws\S3\S3ClientInterface;
use Carbon\CarbonImmutable;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\ConnectionInterface;
use Mockery\MockInterface;

function makeDeleteService(
    ConnectionInterface $connection,
    BackupManager $manager,
    DaemonBackupRepository $daemon,
): DeleteBackupService {
    return new DeleteBackupService($connection, $manager, $daemon);
}

/**
 * A partial-mock Backup with the attributes the service reads. delete() is
 * only stubbed when the caller opts in, so tests can assert it is not called.
 */
function deleteFakeBackup(array $overrides = []): Backup
{
    $backup = Mockery::mock(Backup::class)->makePartial();
    $backup->uuid = $overrides['uuid'] ?? 'backup-uuid';
    $backup->is_locked = $overrides['is_locked'] ?? false;
    $backup->is_successful = $overrides['is_successful'] ?? true;
    $backup->completed_at = $overrides['completed_at'] ?? CarbonImmutable::now();
    $backup->disk = $overrides['disk'] ?? Backup::ADAPTER_WINGS;
    $backup->setRelation('server', $overrides['server'] ?? deleteFakeServer());

    if ($overrides['delete_expected'] ?? true) {
        $backup->shouldReceive('delete')->once();
    } else {
        $backup->shouldNotReceive('delete');
    }

    return $backup;
}

function deleteFakeServer(): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $server->uuid = 'server-uuid';

    return $server;
}

function fakeConnection(): ConnectionInterface
{
    return Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();
}

function fakeDaemonRepository(): DaemonBackupRepository|MockInterface
{
    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->andReturnSelf();

    return $daemon;
}

afterEach(function () {
    Mockery::close();
});

it('throws a BackupLockedException when a successful completed backup is locked', function () {
    $connection = fakeConnection();
    $manager = Mockery::mock(BackupManager::class);
    $daemon = fakeDaemonRepository();
    $daemon->shouldNotReceive('delete');

    $backup = deleteFakeBackup(['is_locked' => true, 'delete_expected' => false]);

    $service = makeDeleteService($connection, $manager, $daemon);

    expect(fn () => $service->handle($backup))->toThrow(BackupLockedException::class);
});

it('deletes a failed backup even if it is locked', function () {
    $connection = fakeConnection();
    $manager = Mockery::mock(BackupManager::class);
    $daemon = fakeDaemonRepository();
    $daemon->shouldReceive('delete')->once()->andReturn(new Response(204));

    $backup = deleteFakeBackup(['is_locked' => true, 'is_successful' => false, 'completed_at' => null]);

    makeDeleteService($connection, $manager, $daemon)->handle($backup);
});

it('deletes a wings backup from the daemon and then the panel row', function () {
    $connection = fakeConnection();
    $manager = Mockery::mock(BackupManager::class);
    $daemon = fakeDaemonRepository();
    $daemon->shouldReceive('delete')->once()->andReturn(new Response(204));

    $backup = deleteFakeBackup();

    makeDeleteService($connection, $manager, $daemon)->handle($backup);
});

it('treats a daemon 404 as an already-deleted backup and removes the row', function () {
    $connection = fakeConnection();
    $manager = Mockery::mock(BackupManager::class);
    $daemon = fakeDaemonRepository();
    $daemon->shouldReceive('delete')->once()->andThrow(
        new DaemonConnectionException(
            new ClientException('Not Found', new Request('DELETE', 'http://example.com'), new Response(404))
        )
    );

    $backup = deleteFakeBackup();

    makeDeleteService($connection, $manager, $daemon)->handle($backup);
});

it('rethrows non-404 daemon errors and does not delete the panel row', function () {
    $connection = fakeConnection();
    $manager = Mockery::mock(BackupManager::class);
    $daemon = fakeDaemonRepository();
    $daemon->shouldReceive('delete')->once()->andThrow(
        new DaemonConnectionException(
            new ClientException('Server Error', new Request('DELETE', 'http://example.com'), new Response(500))
        )
    );

    $backup = deleteFakeBackup(['delete_expected' => false]);

    $service = makeDeleteService($connection, $manager, $daemon);

    expect(fn () => $service->handle($backup))->toThrow(DaemonConnectionException::class);
});

it('deletes an S3 backup object and the panel row inside a transaction', function () {
    $connection = fakeConnection();
    $client = Mockery::mock(S3ClientInterface::class);
    $client->shouldReceive('deleteObject')->once()->with([
        'Bucket' => 'backups-bucket',
        'Key' => 'server-uuid/backup-uuid.tar.gz',
    ]);
    $adapter = Mockery::mock(S3Filesystem::class);
    $adapter->shouldReceive('getClient')->andReturn($client);
    $adapter->shouldReceive('getBucket')->andReturn('backups-bucket');

    $manager = Mockery::mock(BackupManager::class);
    $manager->shouldReceive('adapter')->with(Backup::ADAPTER_AWS_S3)->andReturn($adapter);

    $daemon = fakeDaemonRepository();
    $daemon->shouldNotReceive('delete');

    $backup = deleteFakeBackup(['disk' => Backup::ADAPTER_AWS_S3]);

    makeDeleteService($connection, $manager, $daemon)->handle($backup);
});
