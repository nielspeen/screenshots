<?php

declare(strict_types=1);

namespace Screenshots;

/** Decides which URLs we are willing to point a browser at. */
final class UrlGuard
{
    private const MAX_REDIRECTS = 5;

    /**
     * @param list<string> $domains
     * @param bool $anyPublicHost trusted caller: hosts off the whitelist are fine, if they are on the public internet
     * @param (\Closure(string): list<string>)|null $resolver host to IP addresses; for tests
     */
    public function __construct(
        private array $domains,
        private bool $anyPublicHost = false,
        private ?\Closure $resolver = null,
    ) {
    }

    /**
     * Validates the URL and rebuilds it from its parts, so the browser can't
     * read a different host into it than we did.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new HttpError(400, 'Invalid url');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new HttpError(400, 'Invalid url');
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new HttpError(400, 'Only http and https urls are supported');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new HttpError(400, 'Credentials in the url are not allowed');
        }
        if (isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80)) {
            throw new HttpError(400, 'Non-default ports are not allowed');
        }

        // Plain ASCII hostnames only; IDNs have to be passed as punycode.
        $host = rtrim(strtolower($parts['host']), '.');
        if (!preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host)) {
            throw new HttpError(400, 'Invalid host');
        }
        if (!$this->allows($host)) {
            if (!$this->anyPublicHost) {
                throw new HttpError(403, "Domain not whitelisted: $host");
            }
            $this->assertPublic($host);
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . ($parts['path'] ?? '/') . $query;
    }

    public function allows(string $host): bool
    {
        foreach ($this->domains as $domain) {
            $domain = strtolower($domain);
            if (str_starts_with($domain, '*.')) {
                if (str_ends_with($host, substr($domain, 1))) {
                    return true;
                }
            } elseif ($host === $domain) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hosts we know nothing about must not lead into our own network: no loopback, private,
     * link-local (cloud metadata) or CGNAT (Tailscale) addresses. Chrome resolves the name
     * again by itself, so this is a courtesy check; the real fence is the server's firewall.
     */
    private function assertPublic(string $host): void
    {
        $addresses = $this->resolver ? ($this->resolver)($host) : self::addressesOf($host);
        if ($addresses === []) {
            throw new HttpError(400, "Host does not resolve: $host");
        }
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                throw new HttpError(403, "Host is not on the public internet: $host");
            }
        }
    }

    /** @return list<string> */
    private static function addressesOf(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            $addresses[] = $record['ipv6'];
        }

        return $addresses;
    }

    /**
     * Makes sure the URL ends in a 2xx response and that every redirect on the way
     * is a URL we would have accepted as well. Chrome happily screenshots error pages
     * and follows any redirect, and we don't want either of those cached for a month.
     */
    public function preflight(string $url): void
    {
        $context = stream_context_create(['http' => [
            'follow_location' => 0,
            'ignore_errors' => true,
            'timeout' => 10,
            'user_agent' => 'Mozilla/5.0 (compatible; screenshots-preflight)',
        ]]);

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $headers = @get_headers($url, true, $context);
            if ($headers === false || !preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $headers[0], $m)) {
                throw new HttpError(502, 'Target could not be reached');
            }

            $status = (int) $m[1];
            $location = array_change_key_case($headers)['location'] ?? null;
            if ($status >= 300 && $status < 400 && $location !== null) {
                $location = is_array($location) ? end($location) : $location;
                try {
                    $url = $this->normalize(self::resolve($url, $location));
                } catch (HttpError $e) {
                    throw new HttpError($e->status, 'Redirect target rejected: ' . $e->getMessage());
                }
                continue;
            }
            if ($status >= 200 && $status < 300) {
                return;
            }

            throw new HttpError(502, "Target responded with HTTP $status");
        }

        throw new HttpError(502, 'Target redirects too often');
    }

    /** Resolves a Location header against the (normalized) URL it came from. */
    public static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        return $origin . preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/') . $location;
    }
}
