<?php

use App\Contracts\Repository\DatabaseRepositoryInterface;
use App\Extensions\DynamicDatabaseConnection;
use App\Models\Database;
use App\Services\Databases\DatabasePasswordService;
use Illuminate\Database\ConnectionInterface;

function makeDatabasePasswordService(array $overrides = []): DatabasePasswordService
{
    $connection = $overrides['connection'] ?? Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();
    $dynamic = $overrides['dynamic'] ?? Mockery::mock(DynamicDatabaseConnection::class);
    $repository = $overrides['repository'] ?? Mockery::mock(DatabaseRepositoryInterface::class);

    return new DatabasePasswordService($connection, $dynamic, fakeDatabaseEncrypter(), $repository);
}

it('rotates the password on the host and persists the new hash', function () {
    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once()->with('dynamic', 3);

    $repository = Mockery::mock(DatabaseRepositoryInterface::class);
    $repository->shouldReceive('withoutFreshModel')->once()->andReturnSelf();
    $repository->shouldReceive('update')
        ->once()
        ->with(5, Mockery::on(fn ($fields) => isset($fields['password']) && strlen($fields['password']) === 24))
        ->andReturn(true);
    $repository->shouldReceive('dropUser')->once()->with('u1_abc123', '%');
    $repository->shouldReceive('createUser')
        ->once()
        ->withArgs(fn ($username, $remote, $password, $max) => $username === 'u1_abc123'
            && $remote === '%'
            && strlen($password) === 24
            && $max === 10);
    $repository->shouldReceive('assignUserToDatabase')->once()->with('s1_playerdata', 'u1_abc123', '%');
    $repository->shouldReceive('flush')->once();

    $database = Mockery::mock(Database::class);
    $database->shouldReceive('getAttribute')->with('database_host_id')->andReturn(3);
    $database->shouldReceive('getAttribute')->with('id')->andReturn(5);
    $database->shouldReceive('getAttribute')->with('username')->andReturn('u1_abc123');
    $database->shouldReceive('getAttribute')->with('remote')->andReturn('%');
    $database->shouldReceive('getAttribute')->with('database')->andReturn('s1_playerdata');
    $database->shouldReceive('getAttribute')->with('max_connections')->andReturn(10);

    $service = makeDatabasePasswordService(['dynamic' => $dynamic, 'repository' => $repository]);

    $password = $service->handle($database);

    // The plaintext is returned to the caller and used for the host user (fake
    // encrypter stores it as-is), so both must match.
    expect(strlen($password))->toBe(24);
});

it('recreates the user without a max_connections clause when it is not set', function () {
    $dynamic = Mockery::mock(DynamicDatabaseConnection::class);
    $dynamic->shouldReceive('set')->once()->with('dynamic', 1);

    $repository = Mockery::mock(DatabaseRepositoryInterface::class);
    $repository->shouldReceive('withoutFreshModel')->once()->andReturnSelf();
    $repository->shouldReceive('update')->once()->andReturn(true);
    $repository->shouldReceive('dropUser')->once();
    $repository->shouldReceive('createUser')
        ->once()
        ->withArgs(fn ($username, $remote, $password, $max) => is_null($max));
    $repository->shouldReceive('assignUserToDatabase')->once();
    $repository->shouldReceive('flush')->once();

    $database = Mockery::mock(Database::class);
    $database->shouldReceive('getAttribute')->with('database_host_id')->andReturn(1);
    $database->shouldReceive('getAttribute')->with('id')->andReturn(6);
    $database->shouldReceive('getAttribute')->with('username')->andReturn('u1_xyz');
    $database->shouldReceive('getAttribute')->with('remote')->andReturn('10.0.0.1');
    $database->shouldReceive('getAttribute')->with('database')->andReturn('s1_other');
    $database->shouldReceive('getAttribute')->with('max_connections')->andReturn(null);

    $service = makeDatabasePasswordService(['dynamic' => $dynamic, 'repository' => $repository]);

    $service->handle($database);
});
