<?php

namespace justinholtweb\appleseed\helpers;

use Craft;

/**
 * Which links Appleseed will fetch.
 *
 * Links come from content, so anyone who can edit an entry decides what the server requests. Until
 * 5.2.5 that included `http://169.254.169.254/` (cloud metadata), `http://localhost:6379/` and any
 * intranet host — and the dashboard then reported whether each one answered, and with what. Hosts
 * that resolve to a private, loopback, link-local or otherwise reserved address are not requested.
 *
 * The site's own hosts are the exception: checking internal links is the point of the plugin, and a
 * site under development often runs on 127.0.0.1.
 */
final class UrlGuard
{
    /**
     * Why `$url` must not be requested, or null if it may be.
     */
    public static function refusal(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower(trim((string)($parts['host'] ?? ''), '[]'));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return Craft::t('appleseed', 'Not checked: only http and https links are fetched.');
        }

        if (in_array($host, self::siteHosts(), true)) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : self::resolve($host);

        // A host that doesn't resolve is the link checker's to report, as a DNS error.
        foreach ($addresses as $ip) {
            if (!self::isPublic($ip)) {
                return Craft::t('appleseed', 'Not checked: {host} resolves to a private or internal address.', ['host' => $host]);
            }
        }

        return null;
    }

    /**
     * Guzzle's `on_redirect` hook: a redirect to an address that may not be requested stops the
     * request there, rather than being followed.
     *
     * @throws UrlRefusedException
     */
    public static function onRedirect(mixed $request, mixed $response, mixed $uri): void
    {
        if (($refusal = self::refusal((string)$uri)) !== null) {
            throw new UrlRefusedException($refusal);
        }
    }

    /**
     * Whether an address is on the public internet. Private, loopback, link-local (which includes
     * the 169.254.169.254 metadata service), carrier-grade NAT, unspecified and reserved ranges are
     * not.
     */
    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Not covered by PHP's flags.
        foreach (['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', 'fc00::/7', 'fe80::/10', '::ffff:0:0/96'] as $cidr) {
            if (self::inRange($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** @return string[] */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (!empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return $addresses;
    }

    /** @return string[] */
    private static function siteHosts(): array
    {
        $hosts = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $host = parse_url((string)$site->getBaseUrl(), PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int)$bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
    }
}
