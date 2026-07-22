<?php

namespace App\Services\Servers;

use App\Contracts\Repository\SettingsRepositoryInterface;
use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\ServerSubdomain;
use Illuminate\Contracts\Encryption\Encrypter;
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
        return $this->apiToken() !== null
            && $this->zoneId() !== null
            && $this->domain() !== null
            && in_array('subdomain', $server->egg->features ?? [], true);
    }

    public function domain(): ?string
    {
        $value = $this->settings->get('settings::panel:cloudflare:domain', null);

        return $value ? strtolower(trim($value)) : null;
    }

    /**
     * Create (or replace) the server's SRV record and persist it.
     *
     * @throws DisplayException
     */
    public function store(Server $server, string $subdomain): ServerSubdomain
    {
        $subdomain = $this->sanitize($subdomain);

        if ($subdomain === '') {
            throw new DisplayException('Please provide a valid subdomain using only letters, numbers, and dashes.');
        }

        $domain = $this->domain();
        if ($domain === null) {
            throw new DisplayException('Subdomains are not configured on this panel.');
        }

        $existing = ServerSubdomain::query()->where('server_id', $server->id)->first();

        if ($existing?->subdomain === $subdomain && $existing->domain === $domain) {
            return $existing;
        }

        if (! $this->isNameAvailable($subdomain, $domain, $existing?->cf_record_id)) {
            throw new DisplayException(
                'This subdomain is already taken. Try: '.implode(', ', $this->suggest($subdomain, $domain))
            );
        }

        // Replace flow: remove the old CF record first so a failure here
        // leaves the old (still working) record untouched.
        if ($existing?->cf_record_id) {
            $this->deleteRecord($existing->cf_record_id);
        }

        $recordId = $this->createSrvRecord($subdomain, $domain, $server);

        $record = ServerSubdomain::query()->updateOrCreate(
            ['server_id' => $server->id],
            ['subdomain' => $subdomain, 'domain' => $domain, 'cf_record_id' => $recordId]
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

        if (! $record) {
            return;
        }

        if ($record->cf_record_id) {
            $this->deleteRecord($record->cf_record_id);
        }

        $record->forceFill([
            'cf_record_id' => $this->createSrvRecord($record->subdomain, $record->domain, $server),
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

        if ($record->cf_record_id) {
            $this->deleteRecord($record->cf_record_id);
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
    private function suggest(string $subdomain, string $domain): array
    {
        $suggestions = [];

        foreach (range(1, 3) as $i) {
            $candidate = $subdomain.'-'.$i;
            if ($this->isNameAvailable($candidate, $domain)) {
                $suggestions[] = $candidate.'.'.$domain;
            }
        }

        return $suggestions;
    }

    private function isNameAvailable(string $subdomain, string $domain, ?string $ignoreRecordId = null): bool
    {
        $name = $subdomain.'.'.$domain;

        $taken = ServerSubdomain::query()
            ->where('subdomain', $subdomain)
            ->where('domain', $domain)
            ->exists();

        if ($taken) {
            return false;
        }

        foreach ($this->listRecords($name) as $record) {
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
    private function listRecords(string $name): array
    {
        $response = $this->client()->get(self::API_BASE.'/zones/'.$this->zoneId().'/dns_records', [
            'name' => $name,
        ]);

        if (! $response->successful()) {
            throw new DisplayException('Cloudflare API error: '.$this->errorMessage($response->json()));
        }

        return $response->json('result') ?? [];
    }

    private function createSrvRecord(string $subdomain, string $domain, Server $server): string
    {
        $fqdn = $subdomain.'.'.$domain;
        $allocation = $server->allocation;

        $response = $this->client()->post(self::API_BASE.'/zones/'.$this->zoneId().'/dns_records', [
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

    private function deleteRecord(string $recordId): void
    {
        $response = $this->client()->delete(self::API_BASE.'/zones/'.$this->zoneId().'/dns_records/'.$recordId);

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

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken())
            ->acceptJson()
            ->timeout((int) config('panel.guzzle.timeout', 15));
    }

    private function apiToken(): ?string
    {
        $value = $this->settings->get('settings::panel:cloudflare:api_token', null);

        if (empty($value)) {
            return null;
        }

        try {
            return $this->encrypter->decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }

    private function zoneId(): ?string
    {
        $value = $this->settings->get('settings::panel:cloudflare:zone_id', null);

        return $value ?: null;
    }
}
