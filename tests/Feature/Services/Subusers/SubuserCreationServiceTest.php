<?php

use App\Contracts\Repository\UserRepositoryInterface;
use App\Exceptions\Repository\RecordNotFoundException;
use App\Exceptions\Service\Subuser\ServerSubuserExistsException;
use App\Exceptions\Service\Subuser\UserIsServerOwnerException;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Repositories\Eloquent\SubuserRepository;
use App\Services\Subusers\SubuserCreationService;
use App\Services\Users\UserCreationService;
use Illuminate\Database\ConnectionInterface;

function makeSubuserService(array $overrides = []): SubuserCreationService
{
    // transaction() runs the closure directly — no real DB work.
    $connection = $overrides['connection'] ?? Mockery::mock(ConnectionInterface::class)
        ->shouldReceive('transaction')
        ->andReturnUsing(fn ($callback) => $callback())
        ->getMock();
    $subuserRepo = $overrides['subuser_repo'] ?? Mockery::mock(SubuserRepository::class);
    $userCreation = $overrides['user_creation'] ?? Mockery::mock(UserCreationService::class);
    $userRepo = $overrides['user_repo'] ?? Mockery::mock(UserRepositoryInterface::class);

    return new SubuserCreationService($connection, $subuserRepo, $userCreation, $userRepo);
}

beforeEach(function () {
    $this->server = Mockery::mock(Server::class);
    // owner_id=1 (the server owner) and id=10 (the server itself).
    $this->server->shouldReceive('getAttribute')->with('owner_id')->andReturn(1);
    $this->server->shouldReceive('getAttribute')->with('id')->andReturn(10);
});

it('throws when the target user is the server owner', function () {
    $userRepo = Mockery::mock(UserRepositoryInterface::class);
    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('id')->andReturn(1); // owner_id = 1

    $userRepo->shouldReceive('findFirstWhere')
        ->once()
        ->with([['email', '=', 'owner@example.com']])
        ->andReturn($user);

    $service = makeSubuserService(['user_repo' => $userRepo]);

    expect(fn () => $service->handle($this->server, 'owner@example.com', []))
        ->toThrow(UserIsServerOwnerException::class);
});

it('throws when the user is already a subuser on this server', function () {
    $userRepo = Mockery::mock(UserRepositoryInterface::class);
    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('id')->andReturn(2); // not owner

    $userRepo->shouldReceive('findFirstWhere')
        ->once()
        ->andReturn($user);

    $subuserRepo = Mockery::mock(SubuserRepository::class);
    $subuserRepo->shouldReceive('findCountWhere')
        ->once()
        ->with([['user_id', '=', 2], ['server_id', '=', 10]])
        ->andReturn(1);

    $service = makeSubuserService(['user_repo' => $userRepo, 'subuser_repo' => $subuserRepo]);

    expect(fn () => $service->handle($this->server, 'existing@example.com', []))
        ->toThrow(ServerSubuserExistsException::class);
});

it('creates a new subuser for an existing user with the given permissions', function () {
    $userRepo = Mockery::mock(UserRepositoryInterface::class);
    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('id')->andReturn(7);

    $userRepo->shouldReceive('findFirstWhere')->once()->andReturn($user);

    $subuserRepo = Mockery::mock(SubuserRepository::class);
    $subuserRepo->shouldReceive('findCountWhere')->once()->andReturn(0);

    $created = Mockery::mock(Subuser::class);
    $subuserRepo->shouldReceive('create')
        ->once()
        ->with([
            'user_id' => 7,
            'server_id' => 10,
            'permissions' => ['control.start', 'control.stop'],
        ])
        ->andReturn($created);

    $service = makeSubuserService(['user_repo' => $userRepo, 'subuser_repo' => $subuserRepo]);

    $result = $service->handle($this->server, 'new@example.com', ['control.start', 'control.stop', 'control.start']);

    expect($result)->toBe($created);
});

it('creates the user when the email does not yet exist', function () {
    $userRepo = Mockery::mock(UserRepositoryInterface::class);
    $userRepo->shouldReceive('findFirstWhere')->once()->andThrow(new RecordNotFoundException());

    $userCreation = Mockery::mock(UserCreationService::class);
    $newUser = Mockery::mock(User::class);
    $newUser->shouldReceive('getAttribute')->with('id')->andReturn(99);

    $userCreation->shouldReceive('handle')
        ->once()
        ->with(Mockery::on(function ($data) {
            return $data['email'] === 'fresh@example.com'
                && $data['root_admin'] === false
                && str_contains($data['username'], 'fresh');
        }))
        ->andReturn($newUser);

    // For a brand-new user the service does NOT check findCountWhere — it skips
    // straight to creation after the user is made.
    $subuserRepo = Mockery::mock(SubuserRepository::class);
    $created = Mockery::mock(Subuser::class);
    $subuserRepo->shouldReceive('create')->once()->andReturn($created);

    $service = makeSubuserService([
        'user_repo' => $userRepo,
        'user_creation' => $userCreation,
        'subuser_repo' => $subuserRepo,
    ]);

    expect($service->handle($this->server, 'fresh@example.com', ['websocket.connect']))->toBe($created);
});

it('deduplicates the permissions array', function () {
    $userRepo = Mockery::mock(UserRepositoryInterface::class);
    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('id')->andReturn(3);
    $userRepo->shouldReceive('findFirstWhere')->once()->andReturn($user);

    $subuserRepo = Mockery::mock(SubuserRepository::class);
    $subuserRepo->shouldReceive('findCountWhere')->once()->andReturn(0);

    $subuserRepo->shouldReceive('create')
        ->once()
        ->with(Mockery::on(function ($fields) {
            // array_unique keeps the original keys (0 and 2), so compare values.
            return array_values($fields['permissions']) === ['control.start', 'control.stop'];
        }))
        ->andReturn(Mockery::mock(Subuser::class));

    $service = makeSubuserService(['user_repo' => $userRepo, 'subuser_repo' => $subuserRepo]);

    $service->handle($this->server, 'dup@example.com', ['control.start', 'control.start', 'control.stop']);
});
