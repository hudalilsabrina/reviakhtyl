<?php

use App\Enum\JwtScope;
use App\Models\Node;
use App\Models\User;
use App\Services\Nodes\NodeJWTService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Webmozart\Assert\InvalidArgumentException;

/**
 * An Encrypter that stores/decrypts the token as plaintext so the tests never
 * need a real APP_KEY.
 */
function plainEncrypter(): Encrypter
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

function makeJwtNode(): Node
{
    $node = new Node();
    $node->forceFill([
        'id' => 1,
        'uuid' => 'node-uuid',
        'fqdn' => 'node.example.com',
        'scheme' => 'https',
        'daemonListen' => 8080,
        'daemon_token' => str_repeat('s', 64), // >= 256-bit HMAC key
        'daemon_token_id' => 'token-id',
    ]);

    return $node;
}

beforeEach(function () {
    app()->instance(Encrypter::class, plainEncrypter());
});

it('signs a token that validates against the node key', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);
    $service->setScopes(JwtScope::Websocket)
        ->setExpiresAt(CarbonImmutable::now()->addMinutes(10))
        ->setClaims(['server_uuid' => 'server-abc']);

    $token = $service->handle($node, 'identified-by');

    expect($token)->toBeInstanceOf(Plain::class);

    $config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText(str_repeat('s', 64)));
    expect($config->validator()->validate(
        $token,
        new SignedWith(new Sha256(), InMemory::plainText(str_repeat('s', 64)))
    ))->toBeTrue();
});

it('embeds the scope, issuer and audience claims', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);
    $service->setScopes(JwtScope::Websocket, JwtScope::FileDownload);

    $token = $service->handle($node, 'identified-by');

    $claims = $token->claims();

    expect($claims->get('scope'))->toBe('websocket file-download')
        ->and($claims->get('iss'))->toBe(config('app.url'))
        ->and($claims->get('aud'))->toBe(['https://node.example.com:8080']);
});

it('embeds the user_uuid claim when a user is attached', function () {
    $node = makeJwtNode();

    $user = Mockery::mock(User::class);
    $user->shouldReceive('getAttribute')->with('uuid')->andReturn('user-uuid-123');

    $service = app(NodeJWTService::class);
    $service->setScopes(JwtScope::Websocket)->setUser($user);

    $token = $service->handle($node, 'identified-by');

    expect($token->claims()->get('user_uuid'))->toBe('user-uuid-123');
});

it('embeds the subject and a unique id header', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);
    $service->setScopes(JwtScope::Websocket)->setSubject('server-uuid');

    $token = $service->handle($node, 'identified-by');

    expect($token->claims()->get('sub'))->toBe('server-uuid')
        ->and($token->headers()->get('jti'))->toBe(hash('sha256', 'identified-by'))
        ->and($token->claims()->get('unique_id'))->toBeString();
});

it('throws when no scope is provided', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);

    expect(fn () => $service->handle($node, 'identified-by'))
        ->toThrow(InvalidArgumentException::class);
});

it('derives a different identifier hash per subject', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);

    $tokenA = $service->setScopes(JwtScope::Websocket)->handle($node, 'subject-a');
    $service = app(NodeJWTService::class);
    $tokenB = $service->setScopes(JwtScope::Websocket)->handle($node, 'subject-b');

    expect($tokenA->headers()->get('jti'))->not->toBe($tokenB->headers()->get('jti'));
});

it('forces a fresh unique_id per token', function () {
    $node = makeJwtNode();
    $service = app(NodeJWTService::class);

    $tokenA = $service->setScopes(JwtScope::Websocket)->handle($node, 'same-subject');
    $service = app(NodeJWTService::class);
    $tokenB = $service->setScopes(JwtScope::Websocket)->handle($node, 'same-subject');

    expect($tokenA->claims()->get('unique_id'))->not->toBe($tokenB->claims()->get('unique_id'));
});

it('exposes the decrypted daemon token from the node model', function () {
    $node = makeJwtNode();

    expect($node->getDecryptedKey())->toBe(str_repeat('s', 64));
});
