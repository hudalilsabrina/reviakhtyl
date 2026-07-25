<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $server_id
 * @property int|null $cloudflare_domain_id
 * @property string $subdomain
 * @property string $domain
 * @property string $srv_service
 * @property string $srv_proto
 * @property string|null $cf_record_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Server $server
 * @property CloudflareDomain|null $cloudflareDomain
 */
class ServerSubdomain extends Model
{
    public const RESOURCE_NAME = 'server_subdomain';

    protected $table = 'server_subdomains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'server_id' => 'integer',
        'cloudflare_domain_id' => 'integer',
    ];

    protected $attributes = [
        'srv_service' => '_minecraft',
        'srv_proto' => '_tcp',
    ];

    public function getFqdn(): string
    {
        return $this->subdomain.'.'.$this->domain;
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return BelongsTo<CloudflareDomain, $this>
     */
    public function cloudflareDomain(): BelongsTo
    {
        return $this->belongsTo(CloudflareDomain::class);
    }
}
