<?php

use App\Contracts\Repository\NodeRepositoryInterface;
use App\Contracts\Repository\ServerRepositoryInterface;
use App\Exceptions\Service\HasActiveServersException;
use App\Models\Node;
use App\Services\Nodes\NodeCreationService;
use App\Services\Nodes\NodeDeletionService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Translation\Translator;

/**
 * An Encrypter that stores the token as plaintext (tests have no real APP_KEY).
 */
function nodePlainEncrypter(): Encrypter
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

it('generates a uuid and daemon tokens for a new node', function () {
    $repository = Mockery::mock(NodeRepositoryInterface::class);
    $repository->shouldReceive('create')
        ->once()
        ->withArgs(function (array $data, bool $validate, bool $force) {
            return $validate === true && $force === true
                && is_string($data['uuid'])
                && strlen($data['uuid']) === 36
                && strlen($data['daemon_token_id']) === Node::DAEMON_TOKEN_ID_LENGTH
                && ! empty($data['daemon_token']);
        })
        ->andReturnUsing(function (array $data) {
            $node = new Node();
            $node->forceFill($data);

            return $node;
        });

    app()->instance(Encrypter::class, nodePlainEncrypter());

    $service = new NodeCreationService($repository);
    $node = $service->handle(['name' => 'test', 'fqdn' => 'node.example.com']);

    expect($node->uuid)->toBeString()
        ->and($node->daemon_token_id)->toHaveLength(Node::DAEMON_TOKEN_ID_LENGTH)
        ->and($node->daemon_token)->toBeString()
        ->and(strlen($node->daemon_token))->toBe(Node::DAEMON_TOKEN_LENGTH);
});

it('rejects deleting a node that has servers attached', function () {
    $nodeRepository = Mockery::mock(NodeRepositoryInterface::class);
    $serverRepository = Mockery::mock(ServerRepositoryInterface::class);
    $serverRepository->shouldReceive('setColumns')->with('id')->andReturnSelf();
    $serverRepository->shouldReceive('findCountWhere')
        ->once()
        ->with([['node_id', '=', 1]])
        ->andReturn(2);

    $translator = Mockery::mock(Translator::class);
    $translator->shouldReceive('get')->andReturn('A node must have no servers linked to it in order to be deleted.');

    $service = new NodeDeletionService($nodeRepository, $serverRepository, $translator);

    expect(fn () => $service->handle(1))
        ->toThrow(HasActiveServersException::class);

    // The node repository must never be called.
    $nodeRepository->shouldNotReceive('delete');
});

it('deletes a node that has no servers attached', function () {
    $nodeRepository = Mockery::mock(NodeRepositoryInterface::class);
    $serverRepository = Mockery::mock(ServerRepositoryInterface::class);
    $serverRepository->shouldReceive('setColumns')->with('id')->andReturnSelf();
    $serverRepository->shouldReceive('findCountWhere')
        ->once()
        ->with([['node_id', '=', 5]])
        ->andReturn(0);
    $nodeRepository->shouldReceive('delete')->once()->with(5)->andReturn(1);

    $translator = Mockery::mock(Translator::class);

    $service = new NodeDeletionService($nodeRepository, $serverRepository, $translator);

    expect($service->handle(5))->toBe(1);
});

it('accepts a Node model instead of an id', function () {
    $node = new Node();
    $node->forceFill(['id' => 9]);

    $nodeRepository = Mockery::mock(NodeRepositoryInterface::class);
    $serverRepository = Mockery::mock(ServerRepositoryInterface::class);
    $serverRepository->shouldReceive('setColumns')->with('id')->andReturnSelf();
    $serverRepository->shouldReceive('findCountWhere')
        ->once()
        ->with([['node_id', '=', 9]])
        ->andReturn(0);
    $nodeRepository->shouldReceive('delete')->once()->with(9)->andReturn(1);

    $translator = Mockery::mock(Translator::class);

    $service = new NodeDeletionService($nodeRepository, $serverRepository, $translator);

    expect($service->handle($node))->toBe(1);
});
