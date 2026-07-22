<?php

namespace App\Services\Servers;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Models\CloudflareDomain;
use App\Models\Server;
use App\Models\ServerSubdomain;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CloudflareSubdomainService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Encrypter $encrypter,
    ) {}

    /**
     * Whether the feature is configured and enabled for this server's egg.
     */
    public function isEnabledFor(Server $server): bool
    {
        return $this->domains()->isNotEmpty()
            && in_array('subdomain', $server->egg->features ?? [], true);
    }

    /**
     * Enabled domains available for subdomain creation.
     *
     * @return Collection<int, CloudflareDomain>
     */
    public function domains(): Collection
    {
        return CloudflareDomain::query()->where('is_enabled', true)->orderBy('domain')->get();
    }

    /**
     * Create (or replace) the server's SRV record and persist it.
     *
     * @throws DisplayException
     */
    public function store(Server $server, string $subdomain, int $domainId): ServerSubdomain
    {
        $subdomain = $this->sanitize($subdomain);

        if ($subdomain === '') {
            throw new DisplayException('Please provide a valid subdomain using only letters, numbers, and dashes.');
        }

        $domain = CloudflareDomain::query()->where('is_enabled', true)->find($domainId);

        if (! $domain) {
            throw new DisplayException('The selected domain is not available.');
        }

        $existing = ServerSubdomain::query()->where('server_id', $server->id)->first();

        if ($existing?->subdomain === $subdomain && $existing->cloudflare_domain_id === $domain->id) {
            return $existing;
        }

        if (! $this->isNameAvailable($domain, $subdomain, $existing?->cf_record_id)) {
            throw new DisplayException(
                'This subdomain is already taken. Try: '.implode(', ', $this->suggest($domain, $subdomain))
            );
        }

        // Replace flow: remove the old CF record first so a failure here
        // leaves the old (still working) record untouched. Old record may
        // live in a different zone, so resolve its own domain entry.
        if ($existing?->cf_record_id && $existing->cloudflareDomain) {
            $this->deleteRecord($existing->cloudflareDomain, $existing->cf_record_id);
        }

        $recordId = $this->createSrvRecord($domain, $subdomain, $server);

        $record = ServerSubdomain::query()->updateOrCreate(
            ['server_id' => $server->id],
            [
                'cloudflare_domain_id' => $domain->id,
                'subdomain' => $subdomain,
                'domain' => $domain->domain,
                'cf_record_id' => $recordId,
            ]
        );

        return $record;
    }

    /**
     * Re-point an existing subdomain's SRV record at the server's current
     * primary allocation. Used after the primary allocation changes.
     */
    public function sync(Server $server): void
    {
        $record = $server->subdomain;

        if (! $record || ! $record->cloudflareDomain) {
            return;
        }

        if ($record->cf_record_id) {
            $this->deleteRecord($record->cloudflareDomain, $record->cf_record_id);
        }

        $record->forceFill([
            'cf_record_id' => $this->createSrvRecord($record->cloudflareDomain, $record->subdomain, $server),
        ])->save();
    }

    /**
     * Best-effort variant of sync(). Never throws.
     */
    public function syncQuietly(Server $server): void
    {
        try {
            $this->sync($server);
        } catch (\Throwable) {
        }
    }

    /**
     * Remove the server's subdomain and its DNS record.
     */
    public function destroy(Server $server): void
    {
        $record = ServerSubdomain::query()->where('server_id', $server->id)->first();

        if (! $record) {
            return;
        }

        if ($record->cf_record_id && $record->cloudflareDomain) {
            $this->deleteRecord($record->cloudflareDomain, $record->cf_record_id);
        }

        $record->delete();
    }

    /**
     * Best-effort cleanup when a server is deleted. Never throws.
     */
    public function destroyQuietly(Server $server): void
    {
        try {
            $this->destroy($server);
        } catch (\Throwable) {
        }
    }

    /**
     * Verify the stored/derived token for a domain works against Cloudflare.
     *
     * @throws DisplayException
     */
    public function testDomain(CloudflareDomain $domain): void
    {
        $token = $this->apiToken($domain);

        if (empty($token)) {
            throw new DisplayException('No API token available for this domain.');
        }

        $response = $this->client($domain)->get(self::API_BASE.'/zones/'.$domain->zone_id);

        if (! $response->successful()) {
            throw new DisplayException('Cloudflare API error: '.$this->errorMessage($response->json()));
        }
    }

    /**
     * List zones (domains) visible to an API token.
     *
     * @return array<string, string> Map of zone ID => zone name.
     */
    public static function fetchZones(string $apiToken): array
    {
        $response = Http::withToken($apiToken)
            ->acceptJson()
            ->timeout(15)
            ->get(self::API_BASE.'/zones', ['per_page' => 50]);

        if (! $response->successful()) {
            throw new DisplayException('Cloudflare API error: '.$response->json('errors.0.message', 'unexpected response'));
        }

        return collect($response->json('result') ?? [])->pluck('name', 'id')->all();
    }

    public function sanitize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9-]+/', '-')
            ->trim('-')
            ->substr(0, 63)
            ->toString();
    }

    /**
     * @return array<int, string>
     */
    private function suggest(CloudflareDomain $domain, string $subdomain): array
    {
        $suggestions = [];

        foreach (range(1, 3) as $i) {
            $candidate = $subdomain.'-'.$i;
            if ($this->isNameAvailable($domain, $candidate)) {
                $suggestions[] = $candidate.'.'.$domain->domain;
            }
        }

        return $suggestions;
    }

    private function isNameAvailable(CloudflareDomain $domain, string $subdomain, ?string $ignoreRecordId = null): bool
    {
        $taken = ServerSubdomain::query()
            ->where('subdomain', $subdomain)
            ->where('domain', $domain->domain)
            ->exists();

        if ($taken) {
            return false;
        }

        foreach ($this->listRecords($domain, $subdomain.'.'.$domain->domain) as $record) {
            if ($ignoreRecordId !== null && ($record['id'] ?? null) === $ignoreRecordId) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listRecords(CloudflareDomain $domain, string $name): array
    {
        $response = $this->client($domain)->get(self::API_BASE.'/zones/'.$domain->zone_id.'/dns_records', [
            'name' => $name,
        ]);

        if (! $response->successful()) {
            throw new DisplayException('Cloudflare API error: '.$this->errorMessage($response->json()));
        }

        return $response->json('result') ?? [];
    }

    private function createSrvRecord(CloudflareDomain $domain, string $subdomain, Server $server): string
    {
        $fqdn = $subdomain.'.'.$domain->domain;
        $allocation = $server->allocation;

        $response = $this->client($domain)->post(self::API_BASE.'/zones/'.$domain->zone_id.'/dns_records', [
            'type' => 'SRV',
            'name' => '_minecraft._tcp.'.$fqdn,
            'data' => [
                'service' => '_minecraft',
                'proto' => '_tcp',
                'name' => $fqdn,
                'priority' => 0,
                'weight' => 5,
                'port' => $allocation->port,
                'target' => $server->node->fqdn,
            ],
            'ttl' => 1, // Auto.
            'proxied' => false,
        ]);

        if (! $response->successful()) {
            throw new DisplayException('Failed to create DNS record: '.$this->errorMessage($response->json()));
        }

        return $response->json('result.id');
    }

    private function deleteRecord(CloudflareDomain $domain, string $recordId): void
    {
        $response = $this->client($domain)->delete(self::API_BASE.'/zones/'.$domain->zone_id.'/dns_records/'.$recordId);

        if (! $response->successful() && $response->status() !== 404) {
            throw new DisplayException('Failed to delete DNS record: '.$this->errorMessage($response->json()));
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function errorMessage(?array $payload): string
    {
        return collect($payload['errors'] ?? [])->pluck('message')->filter()->implode('; ')
            ?: 'unexpected response';
    }

    private function client(CloudflareDomain $domain): PendingRequest
    {
        return Http::withToken($this->apiToken($domain))
            ->acceptJson()
            ->timeout((int) config('panel.guzzle.timeout', 15));
    }

    /**
     * Resolve the API token for a domain: per-zone token if set, else the
     * global token from settings.
     */
    private function apiToken(CloudflareDomain $domain): ?string
    {
        $value = $domain->api_token ?: $this->settings->get('settings::panel:cloudflare:api_token', null);

        if (empty($value)) {
            return null;
        }

        try {
            return $this->encrypter->decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
