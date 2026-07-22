<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $domain
 * @property string $zone_id
 * @property string|null $api_token
 * @property bool $is_enabled
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|ServerSubdomain[] $subdomains
 */
class CloudflareDomain extends Model
{
    protected $table = 'cloudflare_domains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

    protected $hidden = ['api_token'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    /**
     * @return HasMany<ServerSubdomain, $this>
     */
    public function subdomains(): HasMany
    {
        return $this->hasMany(ServerSubdomain::class);
    }
}
