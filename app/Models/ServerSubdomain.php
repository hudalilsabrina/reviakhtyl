<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property string $subdomain
 * @property string $domain
 * @property string|null $cf_record_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property Server $server
 */
class ServerSubdomain extends Model
{
    public const RESOURCE_NAME = 'server_subdomain';

    protected $table = 'server_subdomains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $casts = [
        'server_id' => 'integer',
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
}
