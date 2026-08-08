<?php

namespace App\Providers;

use App\Enum\ResourceLimit;
use App\Http\Middleware\TrimStrings;
use App\Models\Database;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class RouteConfigServiceProvider extends ServiceProvider
{
    protected const FILE_PATH_REGEX = '/^\/api\/client\/servers\/([a-z0-9-]{36})\/files(\/?$|\/(.)*$)/i';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureBindings();
        $this->configureMiddleware();
    }

    /**
     * Configure route model bindings.
     */
    protected function configureBindings(): void
    {
        Route::model('database', Database::class);
    }

    /**
     * Configure middleware behavior.
     */
    protected function configureMiddleware(): void
    {
        TrimStrings::skipWhen(function (Request $request) {
            return preg_match(self::FILE_PATH_REGEX, $request->getPathInfo()) === 1;
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Authentication rate limiting. For login and checkpoint endpoints we'll apply
        // a limit of 10 requests per minute, for the forgot password endpoint apply a
        // limit of two per minute for the requester so that there is less ability to
        // trigger email spam.
        RateLimiter::for('authentication', function (Request $request) {
            if ($request->route()?->named('auth.post.forgot-password')) {
                return Limit::perMinute(2)->by($request->ip());
            }

            return Limit::perMinute(10)->by($request->ip());
        });

        // Configure the throttles for both the application and client APIs below.
        // This is configurable per-instance in "config/http.php". By default this
        // limiter will be tied to the specific request user, and falls back to the
        // request IP if there is no request user present for the key.
        //
        // This means that an authenticated API user cannot use IP switching to get
        // around the limits.
        RateLimiter::for('api.client', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinutes(
                config('http.rate_limit.client_period'),
                config('http.rate_limit.client')
            )->by($key);
        });

        // Tight limit for Cloudflare subdomain writes — each call hits the
        // Cloudflare API, so prevent hammering from a single account.
        RateLimiter::for('api.subdomain', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // DNS propagation polling; cheap resolver lookup but called on an
        // interval by every open subdomain page.
        RateLimiter::for('api.subdomain.status', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(30)->by($key);
        });

        // Plugin installs/updates hit external registries and Wings pulls.
        RateLimiter::for('api.plugins', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Mod installs/updates hit external registries and Wings pulls.
        RateLimiter::for('api.mods', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Every properties write is a read plus a write against Wings, and the
        // save button is easy to lean on.
        RateLimiter::for('api.properties', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(30)->by($key);
        });

        // Each assistant message can fan out into several paid provider calls,
        // so it is limited far more tightly than an ordinary client request.
        RateLimiter::for('api.chatbot', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinute(10)->by($key);
        });

        // Public status page: unauthenticated, so throttle per IP to slow
        // UUID probing and status polling.
        RateLimiter::for('api.public.status', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        // Telegram webhook: secret-verified, but cap per-IP attempts anyway to
        // dampen secret brute-forcing.
        RateLimiter::for('api.public.webhook', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('api.application', function (Request $request) {
            $key = optional($request->user())->uuid ?: $request->ip();

            return Limit::perMinutes(
                config('http.rate_limit.application_period'),
                config('http.rate_limit.application')
            )->by($key);
        });

        ResourceLimit::boot();
    }
}
