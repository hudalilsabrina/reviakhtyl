<?php

namespace App\Services\Chatbot\Tools\Web;

use App\Enum\ChatbotToolGroup;
use App\Services\Chatbot\ToolContext;
use App\Services\Chatbot\Tools\ChatbotTool;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Fetches a public web page and returns its readable text.
 *
 * Web content is untrusted data — the same framing as file contents in the
 * system prompt — and the panel can be made to fetch anything, so the tool
 * carries its own SSRF guard: only http/https, only public addresses, and
 * redirects are followed one hop at a time with the same check applied to
 * each destination.
 */
class FetchUrlTool extends ChatbotTool
{
    /** How many redirect hops are followed before giving up. */
    private const MAX_REDIRECTS = 5;

    /** The fetched page is trimmed to roughly this many characters. */
    private const MAX_CONTENT_LENGTH = 20000;

    public function name(): string
    {
        return 'fetch_url';
    }

    public function description(): string
    {
        return 'Fetch a public web page (http or https) and return its readable text content, title and final URL after redirects. Use this to read documentation, changelogs, or any page the user asks about. Only public internet addresses are reachable; internal and private addresses are refused.';
    }

    public function group(): ChatbotToolGroup
    {
        return ChatbotToolGroup::Web;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The full http(s) URL to fetch, e.g. "https://example.com/docs".',
                ],
            ],
            'required' => ['url'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return ['url' => 'required|string|max:2048'];
    }

    public function handle(ToolContext $context, array $arguments): array
    {
        $url = trim((string) ($arguments['url'] ?? ''));

        $target = $this->resolvePublicUrl($url);

        if ($target === null) {
            return ['ok' => false, 'error' => 'Only public http(s) URLs can be fetched. Private, internal and non-http addresses are refused.'];
        }

        $hops = 0;

        while (true) {
            $response = $this->fetch($target);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'error' => "The page returned HTTP {$response->status()} and could not be read.",
                ];
            }

            // Follow redirects ourselves so every hop passes the public-address
            // check: a page can redirect to a private address even when the
            // requested URL was public.
            if ($response->redirect() && $hops < self::MAX_REDIRECTS) {
                $location = (string) $response->header('Location');

                $next = $this->resolvePublicUrl($location, $target);

                if ($next === null) {
                    return ['ok' => false, 'error' => 'The page redirected to an address that is not a public http(s) URL, so it was not followed.'];
                }

                $target = $next;
                $hops++;

                continue;
            }

            if ($response->redirect()) {
                return ['ok' => false, 'error' => 'The page kept redirecting and was not followed beyond '.self::MAX_REDIRECTS.' hops.'];
            }

            return [
                'ok' => true,
                'final_url' => $target,
                'status' => $response->status(),
                'content_type' => $response->header('Content-Type'),
                'content' => $this->readableText((string) $response->body()),
            ];
        }
    }

    /**
     * Validates the URL and resolves its host, refusing anything that is not a
     * public http(s) address. Returns the absolute URL to fetch, or null.
     *
     * @param  string|null  $base  the URL to resolve relative locations against
     */
    private function resolvePublicUrl(string $url, ?string $base = null): ?string
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

        if (! $this->isPublicHost($host)) {
            return null;
        }

        // Rebuild so relative redirect targets become absolute and the URL is
        // a single canonical string for the final report.
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
    private function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
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
            if (! $this->isPublicIp($address)) {
                return false;
            }
        }

        return true;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    private function fetch(string $url): Response
    {
        return Http::timeout(20)
            ->connectTimeout(10)
            ->withHeaders(['Accept' => 'text/html,text/plain,application/json,application/xml'])
            ->withoutRedirecting()
            ->get($url);
    }

    /**
     * Strips markup and scripts down to the page's readable text, capped so a
     * single fetch cannot consume the whole context window.
     */
    private function readableText(string $body): string
    {
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body) ?? $body;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) <= self::MAX_CONTENT_LENGTH) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CONTENT_LENGTH).' …[truncated]';
    }
}
