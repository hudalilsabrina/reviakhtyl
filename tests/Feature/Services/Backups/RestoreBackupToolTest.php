<?php

use App\Exceptions\Service\Chatbot\ChatbotException;
use App\Models\Backup;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Agent\DaemonBackupRepository;
use App\Repositories\Eloquent\BackupRepository;
use App\Services\Backups\DownloadLinkService;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\Backups\RestoreBackupTool;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;

function makeRestoreTool(array $overrides = []): RestoreBackupTool
{
    $daemon = $overrides['daemon'] ?? Mockery::mock(DaemonBackupRepository::class);
    $repository = $overrides['repository'] ?? Mockery::mock(BackupRepository::class);
    $links = $overrides['links'] ?? Mockery::mock(DownloadLinkService::class);

    return new RestoreBackupTool($daemon, $repository, $links);
}

function fakeToolServer(?string $status = null): Server
{
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 1;
    $server->uuid = 'server-uuid';
    $server->status = $status;

    return $server;
}

function fakeToolUser(): User
{
    $user = Mockery::mock(User::class)->makePartial();
    $user->id = 1;

    return $user;
}

function fakeToolBackup(array $overrides = []): Backup
{
    $backup = Mockery::mock(Backup::class)->makePartial();
    $backup->uuid = $overrides['uuid'] ?? 'backup-uuid';
    $backup->is_successful = $overrides['is_successful'] ?? true;
    $backup->completed_at = $overrides['completed_at'] ?? CarbonImmutable::now();
    $backup->disk = $overrides['disk'] ?? Backup::ADAPTER_WINGS;

    return $backup;
}

function stubFindBackup(BackupRepository|MockInterface $repository, Backup $backup): void
{
    $builder = Mockery::mock(Builder::class);
    $builder->shouldReceive('where')->with('server_id', 1)->andReturnSelf();
    $builder->shouldReceive('where')->with('uuid', $backup->uuid)->andReturnSelf();
    $builder->shouldReceive('first')->andReturn($backup);
    $repository->shouldReceive('getBuilder')->andReturn($builder);
}

beforeEach(function () {
    Cache::flush();
});

afterEach(function () {
    Mockery::close();
});

it('restores a wings backup without a download url and marks the server restoring', function () {
    $server = fakeToolServer();
    $server->shouldReceive('update')->with(['status' => Server::STATUS_RESTORING_BACKUP])->once();

    $backup = fakeToolBackup();
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->with($server)->once()->andReturnSelf();
    $daemon->shouldReceive('restore')->once()
        ->withArgs(fn ($b, $url, $truncate) => $b === $backup && is_null($url) && $truncate === false)
        ->andReturn(new Response(202));

    $links = Mockery::mock(DownloadLinkService::class);
    $links->shouldNotReceive('handle');

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository, 'links' => $links]);

    $result = $tool->handle(new ToolContext($server, fakeToolUser()), [
        'backup_uuid' => $backup->uuid,
    ]);

    expect($result['backup_uuid'])->toBe($backup->uuid)
        ->and($result['truncate'])->toBe(false);
});

it('passes truncate through to the daemon call', function () {
    $server = fakeToolServer();
    $server->shouldReceive('update')->with(['status' => Server::STATUS_RESTORING_BACKUP])->once();

    $backup = fakeToolBackup();
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->with($server)->once()->andReturnSelf();
    $daemon->shouldReceive('restore')->once()
        ->withArgs(fn ($b, $url, $truncate) => $b === $backup && $truncate === true)
        ->andReturn(new Response(202));

    $links = Mockery::mock(DownloadLinkService::class);
    $links->shouldNotReceive('handle');

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository, 'links' => $links]);

    $tool->handle(new ToolContext($server, fakeToolUser()), [
        'backup_uuid' => $backup->uuid,
        'truncate' => true,
    ]);
});

it('generates a signed url and passes it for an S3 backup', function () {
    $server = fakeToolServer();
    $server->shouldReceive('update')->with(['status' => Server::STATUS_RESTORING_BACKUP])->once();

    $backup = fakeToolBackup(['disk' => Backup::ADAPTER_AWS_S3]);
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $user = fakeToolUser();
    $links = Mockery::mock(DownloadLinkService::class);
    $links->shouldReceive('handle')->once()->with($backup, $user)->andReturn('https://signed-url.test/x');

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->with($server)->once()->andReturnSelf();
    $daemon->shouldReceive('restore')->once()
        ->withArgs(fn ($b, $url, $truncate) => $b === $backup && $url === 'https://signed-url.test/x' && $truncate === false)
        ->andReturn(new Response(202));

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository, 'links' => $links]);

    $tool->handle(new ToolContext($server, $user), ['backup_uuid' => $backup->uuid]);
});

it('refuses to restore when the server is in a transitional state', function () {
    $server = fakeToolServer(status: 'transferring');
    $server->shouldNotReceive('update');

    $backup = fakeToolBackup();
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldNotReceive('setServer');

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository]);

    expect(fn () => $tool->handle(new ToolContext($server, fakeToolUser()), ['backup_uuid' => $backup->uuid]))
        ->toThrow(ChatbotException::class);
});

it('refuses to restore a backup that failed or never completed', function () {
    $server = fakeToolServer();
    $server->shouldNotReceive('update');

    $backup = fakeToolBackup(['is_successful' => false, 'completed_at' => null]);
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldNotReceive('setServer');

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository]);

    expect(fn () => $tool->handle(new ToolContext($server, fakeToolUser()), ['backup_uuid' => $backup->uuid]))
        ->toThrow(ChatbotException::class);
});

it('enforces the per-server restore allowance', function () {
    $server = fakeToolServer();
    $backup = fakeToolBackup();
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->with($server)->times(3)->andReturnSelf();
    $daemon->shouldReceive('restore')->times(3)->andReturn(new Response(202));

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository]);

    // ResourceLimit::Backup allows 3 restores per 15 minutes.
    foreach (range(1, 3) as $i) {
        $tool->handle(new ToolContext($server, fakeToolUser()), ['backup_uuid' => $backup->uuid]);
    }

    expect(fn () => $tool->handle(new ToolContext($server, fakeToolUser()), ['backup_uuid' => $backup->uuid]))
        ->toThrow(ChatbotException::class);
});

it('reverts the restoring status when the daemon rejects the restore', function () {
    $server = fakeToolServer();
    $server->shouldReceive('update')->with(['status' => Server::STATUS_RESTORING_BACKUP])->once();
    $server->shouldReceive('update')->with(['status' => null])->once();

    $backup = fakeToolBackup();
    $repository = Mockery::mock(BackupRepository::class);
    stubFindBackup($repository, $backup);

    $daemon = Mockery::mock(DaemonBackupRepository::class);
    $daemon->shouldReceive('setServer')->with($server)->once()->andReturnSelf();
    $daemon->shouldReceive('restore')->once()->andThrow(new RuntimeException('daemon unreachable'));

    $tool = makeRestoreTool(['daemon' => $daemon, 'repository' => $repository]);

    expect(fn () => $tool->handle(new ToolContext($server, fakeToolUser()), ['backup_uuid' => $backup->uuid]))
        ->toThrow(RuntimeException::class);
});
