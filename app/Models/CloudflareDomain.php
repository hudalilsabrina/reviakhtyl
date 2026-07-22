<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $domain
 * @property string $zone_id
 * @property bool $is_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Collection|ServerSubdomain[] $subdomains
 */
class CloudflareDomain extends Model
{
    protected $table = 'cloudflare_domains';

    protected $guarded = ['id', self::CREATED_AT, self::UPDATED_AT];

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
