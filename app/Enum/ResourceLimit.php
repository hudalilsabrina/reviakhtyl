<?php

namespace App\Enum;

use App\Models\Server;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Webmozart\Assert\Assert;

/**
 * A basic resource throttler for individual servers. This is applied in addition
 * to existing rate limits and allows the code to slow down speedy users that might
 * be creating resources a little too quickly for comfort. This throttle generally
 * only applies to creation flows, and not general view/edit/delete flows.
 */
enum ResourceLimit
{
    case Allocation;
    case Backup;
    case Database;
    case Schedule;
    case Subuser;
    case Websocket;
    case FilePull;

    public function throttleKey(): string
    {
        return mb_strtolower("api.client:server-resource:{$this->name}");
    }

    /**
     * Returns a middleware that will throttle the specific resource by server. This
     * throttle applies to any user making changes to that resource on the specific
     * server, it is NOT per-user.
     */
    public function middleware(): string
    {
        return ThrottleRequests::using($this->throttleKey());
    }

    /**
     * Consumes one unit of this server's allowance outside the HTTP middleware,
     * returning false once the allowance is spent.
     *
     * The cache key deliberately reproduces the one ThrottleRequests derives for
     * a named limiter — md5(limiterName . key), its default hashed form — so a
     * caller here and a request through the middleware draw on the same bucket
     * rather than each getting a full quota. If that derivation ever changes, or
     * key hashing is turned off, the two simply stop sharing: the limit still
     * applies, it is just counted separately. This fails safe either way.
     */
    public function hit(Server $server): bool
    {
        $limit = $this->limit();
        $key = md5($this->throttleKey().$server->uuid);

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $limit->decaySeconds);

        return true;
    }

    public function limit(): Limit
    {
        return match ($this) {
            self::Backup => Limit::perMinutes(15, 3),
            self::Database => Limit::perMinute(2),
            self::FilePull => Limit::perMinutes(10, 5),
            self::Subuser => Limit::perMinutes(15, 10),
            self::Websocket => Limit::perMinute(5),
            default => Limit::perMinute(2),
        };
    }

    public static function boot(): void
    {
        foreach (self::cases() as $case) {
            RateLimiter::for($case->throttleKey(), function (Request $request) use ($case) {
                Assert::isInstanceOf($server = $request->route()->parameter('server'), Server::class);

                return $case->limit()->by($server->uuid);
            });
        }
    }
}
