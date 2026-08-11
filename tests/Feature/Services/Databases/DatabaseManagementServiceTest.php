<?php

use App\Exceptions\Service\Database\DatabaseClientFeatureNotEnabledException;
use App\Exceptions\Service\Database\TooManyDatabasesException;
use App\Extensions\DynamicDatabaseConnection;
use App\Models\Database;
use App\Models\Server;
use App\Repositories\Eloquent\DatabaseRepository;
use App\Services\Activity\ActivityLogService;
use App\Services\Databases\DatabaseManagementService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Encrypter that returns the input unchanged — lets tests pass a raw password.
 */
function fakeDatabaseEncrypter(): Encrypter
{
    return new class implements Encrypter
    {
        public function encrypt($value, $serialize = true)
        {
            return $value;
        }

        public function decrypt($payload, $unserialize = true)
        {
            return $payload;
        }

        public function getKey()
        {
            return 'fake';
        }

        public function getAllKeys(): array
        {
            return ['fake'];
        }

        public function getPreviousKeys(): array
        {
            return [];
        }
    };
}

function makeDatabaseManagementService(array $overrides = []): DatabaseManagementService
{
    $connection = $overrides['connection'] ?? Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();
    $dynamic = $overrides['dynamic'] ?? Mockery::mock(DynamicDatabaseConnection::class);
    $repository = $overrides['repository'] ?? Mockery::mock(DatabaseRepository::class);
    $logService = $overrides['log_service'] ?? Mockery::mock(ActivityLogService::class);

    return new DatabaseManagementService($connection, $dynamic, fakeDatabaseEncrypter(), $repository, $logService);
}

it('generates unique database names with the server id prefix', function () {
    expect(DatabaseManagementService::generateUniqueDatabaseName('playerdata', 1))->toBe('s1_playerdata')
        ->and(DatabaseManagementService::generateUniqueDatabaseName('PlayerData', 42))->toBe('s42_PlayerData');
});

it('caps generated database names at 48 characters including the prefix', function () {
    $long = str_repeat('a', 80);

    $result = DatabaseManagementService::generateUniqueDatabaseName($long, 1);

    expect($result)->toBe('s1_'.str_repeat('a', 45))
        ->and(strlen($result))->toBe(48);
});

it('throws when client database creation is disabled', function () {
    config(['panel.client_features.databases.enabled' => false]);

    $service = makeDatabaseManagementService();
    $server = Mockery::mock(Server::class);

    expect(fn () => $service->create($server, ['database' => 's1_playerdata']))
        ->toThrow(DatabaseClientFeatureNotEnabledException::class);
});

it('throws when the server has reached its database limit', function () {
    config(['panel.client_features.databases.enabled' => true]);

    $relation = Mockery::mock(HasMany::class);
    $relation->shouldReceive('count')->once()->andReturn(2);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('database_limit')->andReturn(2);
    $server->shouldReceive('databases')->once()->andReturn($relation);

    $service = makeDatabaseManagementService();

    expect(fn () => $service->create($server, ['database' => 's1_playerdata']))
        ->toThrow(TooManyDatabasesException::class);
});

it('rejects database names without the server id prefix', function () {
    config(['panel.client_features.databases.enabled' => true]);

    $relation = Mockery::mock(HasMany::class);
    $relation->shouldReceive('count')->once()->andReturn(0);

    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('database_limit')->andReturn(10);
    $server->shouldReceive('databases')->once()->andReturn($relation);

    $service = makeDatabaseManagementService();

    expect(fn () => $service->create($server, ['database' => 'no_prefix']))
        ->toThrow(InvalidArgumentException::class);
});

it('skips the limit validation when disabled', function () {
    config(['panel.client_features.databases.enabled' => true]);

    // No relation count expectation: the service must not touch databases().
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getAttribute')->with('database_limit')->andReturn(0);

    $service = makeDatabaseManagementService();
    $service->setValidateDatabaseLimit(false);

    expect(fn () => $service->create($server, ['database' => 'no_prefix']))
        ->toThrow(InvalidArgumentException::class);
});

it('drops the database and user on the host before deleting the model', function () {
    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once()->with('dynamic', 3);

    $repository = Mockery::mock(DatabaseRepository::class);
    $repository->shouldReceive('dropDatabase')->once()->with('s1_playerdata');
    $repository->shouldReceive('dropUser')->once()->with('u1_abc123', '%');
    $repository->shouldReceive('flush')->once();

    $logService = Mockery::mock(ActivityLogService::class);
    $logService->shouldReceive('clone')->once()->andReturnSelf();
    $logService->shouldReceive('subject')->once()->andReturnSelf();
    $logService->shouldReceive('property')->once()->andReturnSelf();
    $logService->shouldReceive('event')->once()->with('server:database.delete')->andReturnSelf();
    $logService->shouldReceive('log')->once();

    $database = Mockery::mock(Database::class);
    $database->shouldReceive('getAttribute')->with('database_host_id')->andReturn(3);
    $database->shouldReceive('getAttribute')->with('database')->andReturn('s1_playerdata');
    $database->shouldReceive('getAttribute')->with('username')->andReturn('u1_abc123');
    $database->shouldReceive('getAttribute')->with('remote')->andReturn('%');
    $database->shouldReceive('delete')->once()->andReturn(true);

    $service = makeDatabaseManagementService(['dynamic' => $dynamic, 'repository' => $repository, 'log_service' => $logService]);

    expect($service->delete($database))->toBeTrue();
});
