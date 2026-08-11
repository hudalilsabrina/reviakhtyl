<?php

namespace App\Support;

use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;

/**
 * Validates that a URL is a public http(s) address before it is fetched by
 * the panel or forwarded to Wings. Prevents SSRF to private/internal hosts
 * (127.0.0.1, 169.254.169.254, RFC1918 space, link-local, etc.).
 */
class PublicHttpGuard
{
    /**
     * Returns the canonical absolute public http(s) URL, or null when the URL
     * is not http(s), has no resolvable host, or resolves to any private or
     * reserved address. Resolves relative targets against $base when given.
     */
    public static function resolvePublicUrl(string $url, ?string $base = null): ?string
    {
        if ($base !== null) {
            $url = UriResolver::resolve(
                Utils::uriFor($base),
                Utils::uriFor($url),
            )->__toString();
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $host = (string) $parts['host'];

        if (! self::isPublicHost($host)) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$port.$path.$query;
    }

    /**
     * True when the hostname resolves to at least one public IP and to no
     * private or reserved ones. A name with no DNS answer is refused rather
     * than fetched blind.
     */
    public static function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = array_filter(array_map(
            fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        ));

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
