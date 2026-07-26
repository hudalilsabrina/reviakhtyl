<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property string $provider
 * @property string $project_id
 * @property string $slug
 * @property string $title
 * @property string $version_id
 * @property string $version_number
 * @property string $file_name
 * @property string|null $icon_url
 * @property bool $disabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Server $server
 */
class ServerMod extends Model
{
    public const RESOURCE_NAME = 'server_mod';

    protected $table = 'server_mods';

    protected $fillable = [
        'server_id',
        'provider',
        'project_id',
        'slug',
        'title',
        'version_id',
        'version_number',
        'file_name',
        'icon_url',
    ];

    protected $appends = ['disabled'];

    /**
     * A mod is disabled by renaming its jar, so the suffix is the state.
     */
    public function getDisabledAttribute(): bool
    {
        return str_ends_with($this->file_name, '.disabled');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
