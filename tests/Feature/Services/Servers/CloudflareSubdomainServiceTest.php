<?php

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Services\Servers\CloudflareSubdomainService;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An Encrypter that returns the input unchanged — lets tests pass a raw token.
 */
function fakeEncrypter(): Encrypter
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

function makeSubdomainService(array $settings = []): CloudflareSubdomainService
{
    $repo = Mockery::mock(SettingsRepositoryInterface::class);
    $repo->shouldReceive('get')
        ->andReturnUsing(fn (string $key, mixed $default = null) => $settings[$key] ?? $default);

    return new CloudflareSubdomainService($repo, fakeEncrypter());
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('sanitizes a subdomain to lowercase, dashes, and a 63-char cap', function () {
    $service = makeSubdomainService();

    expect($service->sanitize('  My_SERVER  '))->toBe('my-server')
        ->and($service->sanitize('-leading and trailing-'))->toBe('leading-and-trailing')
        ->and($service->sanitize(str_repeat('a', 80)))->toBe(str_repeat('a', 63))
        ->and($service->sanitize(''))->toBe('server')
        ->and($service->sanitize('UPPER-case'))->toBe('upper-case');
});

it('falls back to empty-string → server on fully-invalid input', function () {
    $service = makeSubdomainService();

    // All-invalid characters collapse to '-', then get trimmed away → empty.
    expect($service->sanitize('!!!'))->toBe('server');
});

it('exposes srv service/proto fallbacks for legacy rows', function () {
    // Constructed in-memory with no DB.
    $model = new App\Models\ServerSubdomain();
    $model->forceFill([
        'server_id' => 1,
        'subdomain' => 'test',
        'domain' => 'example.com',
        'srv_service' => null,
        'srv_proto' => null,
    ]);

    expect($model->getSrvService())->toBe('_minecraft')
        ->and($model->getSrvProto())->toBe('_tcp')
        ->and($model->getFqdn())->toBe('test.example.com');
});

it('lists all SRV records across pages', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone1/dns_records*' => function ($request) {
            $page = (int) $request['page'];
            $perPage = (int) $request['per_page'];

            if ($page === 1) {
                $result = [];
                foreach (range(0, $perPage - 1) as $i) {
                    $result[] = ['id' => "rec-1-{$i}", 'name' => '_minecraft._tcp.srv-'.$i.'.example.com'];
                }

                return Http::response(['result' => $result]);
            }

            return Http::response(['result' => [
                ['id' => 'rec-2-1', 'name' => '_minecraft._tcp.srv-101.example.com'],
                ['id' => 'rec-2-2', 'name' => '_minecraft._tcp.srv-102.example.com'],
            ]]);
        },
    ]);

    $domain = new App\Models\CloudflareDomain();
    $domain->forceFill(['id' => 1, 'domain' => 'example.com', 'zone_id' => 'zone1', 'is_enabled' => true]);

    $service = makeSubdomainService(['settings::panel:cloudflare:api_token' => 'token']);

    $records = $service->listSrvRecords($domain);

    expect(count($records))->toBeGreaterThan(100) // page 1 (100) + page 2 (2)
        ->and($records[0]['id'])->toBe('rec-1-0')
        ->and($records[100]['id'])->toBe('rec-2-1');
});

it('sends the api token and zone id on record listing', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zoneA/dns_records*' => Http::response(['result' => []]),
    ]);

    $domain = new App\Models\CloudflareDomain();
    $domain->forceFill(['id' => 2, 'domain' => 'test.org', 'zone_id' => 'zoneA', 'is_enabled' => true]);

    $service = makeSubdomainService(['settings::panel:cloudflare:api_token' => 'secret-token']);

    $service->listSrvRecords($domain);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret-token')
        && str_contains($request->url(), '/zones/zoneA/dns_records')
        && $request['type'] === 'SRV');
});
