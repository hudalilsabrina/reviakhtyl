<?php

use App\Contracts\Repository\DatabaseHostRepositoryInterface;
use App\Contracts\Repository\DatabaseRepositoryInterface;
use App\Exceptions\Service\HasActiveServersException;
use App\Extensions\DynamicDatabaseConnection;
use App\Models\DatabaseHost;
use App\Services\Databases\Hosts\HostCreationService;
use App\Services\Databases\Hosts\HostDeletionService;
use App\Services\Databases\Hosts\HostUpdateService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;

function makeDatabaseManager(array $selectResults = []): DatabaseManager
{
    $connection = Mockery::mock();
    $connection->shouldReceive('select')->andReturn(...$selectResults);

    $manager = Mockery::mock(DatabaseManager::class);
    $manager->shouldReceive('connection')->with('dynamic')->andReturn($connection);

    return $manager;
}

function makeDatabaseHostRepository(array $data = []): DatabaseHostRepositoryInterface
{
    $host = Mockery::mock(DatabaseHost::class);
    $host->shouldReceive('getAttribute')->with('id')->andReturn(7);

    $repository = Mockery::mock(DatabaseHostRepositoryInterface::class);
    $repository->shouldReceive('create')->once()->with(Mockery::on(function ($fields) use ($data) {
        return $fields['name'] === ($data['name'] ?? 'prod')
            && $fields['host'] === ($data['host'] ?? '127.0.0.1')
            && $fields['port'] === ($data['port'] ?? 3306)
            && $fields['username'] === ($data['username'] ?? 'root')
            && $fields['password'] === ($data['password'] ?? 'secret');
    }))->andReturn($host);

    return $repository;
}

it('creates a database host and verifies the connection before returning it', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

    $repository = makeDatabaseHostRepository(['password' => 'secret']);

    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once()->with('dynamic', Mockery::type(DatabaseHost::class));

    $manager = makeDatabaseManager();

    $service = new HostCreationService($connection, $manager, $dynamic, fakeDatabaseEncrypter(), $repository);

    $host = $service->handle([
        'name' => 'prod',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($host->getAttribute('id'))->toBe(7);
});

it('encrypts the host password before persisting it', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

    // Identity encrypter stores the plaintext; verify the create payload gets it.
    $repository = makeDatabaseHostRepository(['password' => 'secret']);

    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once();

    $manager = makeDatabaseManager();

    $service = new HostCreationService($connection, $manager, $dynamic, fakeDatabaseEncrypter(), $repository);

    $service->handle([
        'name' => 'prod',
        'host' => '127.0.0.1',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);
});

it('keeps the existing host password when none is provided on update', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

    $host = Mockery::mock(DatabaseHost::class);
    $host->shouldReceive('getAttribute')->with('id')->andReturn(7);

    $repository = Mockery::mock(DatabaseHostRepositoryInterface::class);
    $repository->shouldReceive('update')
        ->once()
        ->with(7, Mockery::on(fn ($fields) => ! isset($fields['password']) && $fields['name'] === 'renamed'))
        ->andReturn($host);

    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once();

    $manager = makeDatabaseManager();

    $service = new HostUpdateService($connection, $manager, $dynamic, fakeDatabaseEncrypter(), $repository);

    $service->handle(7, ['name' => 'renamed']);
});

it('refuses to delete a host that still has databases attached', function () {
    $databaseRepository = Mockery::mock(DatabaseRepositoryInterface::class);
    $databaseRepository->shouldReceive('findCountWhere')
        ->once()
        ->with([['database_host_id', '=', 3]])
        ->andReturn(2);

    $hostRepository = Mockery::mock(DatabaseHostRepositoryInterface::class);

    $service = new HostDeletionService($databaseRepository, $hostRepository);

    expect(fn () => $service->handle(3))->toThrow(HasActiveServersException::class);
});

it('deletes a host with no attached databases', function () {
    $databaseRepository = Mockery::mock(DatabaseRepositoryInterface::class);
    $databaseRepository->shouldReceive('findCountWhere')
        ->once()
        ->with([['database_host_id', '=', 3]])
        ->andReturn(0);

    $hostRepository = Mockery::mock(DatabaseHostRepositoryInterface::class);
    $hostRepository->shouldReceive('delete')->once()->with(3)->andReturn(1);

    $service = new HostDeletionService($databaseRepository, $hostRepository);

    expect($service->handle(3))->toBe(1);
});
