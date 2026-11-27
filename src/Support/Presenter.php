<?php

declare(strict_types=1);

namespace JustinHoltWeb\Appleseed\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use JustinHoltWeb\Appleseed\Models\Link;
use JustinHoltWeb\Appleseed\Models\Scan;
use Throwable;

use function CraftCms\Cms\cp_url;

/**
 * The shapes Appleseed's links and scans take outside the control panel: MCP tool results,
 * GraphQL, the webhook body and the console's JSON. One place, so they agree, and identical to
 * Appleseed 5.3's.
 */
final class Presenter
{
    /**
     * @param  Scan|array<string, mixed>  $scan
     * @return array{id: int, type: string, status: string, totalLinksFound: int, totalLinksChecked: int, brokenCount: int, newBrokenCount: int, redirectCount: int, workingCount: int, entryId: int|null, startedAt: string|null, completedAt: string|null}
     */
    public static function scan(Scan|array $scan): array
    {
        $get = fn (string $key) => $scan instanceof Scan ? $scan->getAttribute($key) : ($scan[$key] ?? null);

        return [
            'id' => (int) $get('id'),
            'type' => (string) $get('type'),
            'status' => (string) $get('status'),
            'totalLinksFound' => (int) $get('totalLinksFound'),
            'totalLinksChecked' => (int) $get('totalLinksChecked'),
            'brokenCount' => (int) $get('brokenCount'),
            'newBrokenCount' => (int) $get('newBrokenCount'),
            'redirectCount' => (int) $get('redirectCount'),
            'workingCount' => (int) $get('workingCount'),
            'entryId' => $get('entryId') !== null ? (int) $get('entryId') : null,
            'startedAt' => self::date($get('startedAt')),
            'completedAt' => self::date($get('completedAt')),
        ];
    }

    /**
     * @param  Link|array<string, mixed>  $link  A link, or a link's data from Reporting (optionally with enriched `sources`).
     * @return array<string, mixed>
     */
    public static function link(Link|array $link, bool $includeSources = true): array
    {
        if ($link instanceof Link) {
            $link = self::linkData($link);
        }

        $chain = $link['redirectChain'] ?? null;

        if (is_string($chain) && $chain !== '') {
            $chain = json_decode($chain, true);
        }

        $data = [
            'id' => (int) $link['id'],
            'url' => (string) $link['url'],
            'host' => $link['host'] ?? LinkUrl::host((string) $link['url']),
            'status' => (string) $link['status'],
            'isBroken' => LinkStatus::isBroken(isset($link['status']) ? (string) $link['status'] : null),
            'statusCode' => isset($link['statusCode']) ? (int) $link['statusCode'] : null,
            'redirectUrl' => $link['redirectUrl'] ?? null,
            'redirectChain' => is_array($chain) ? array_values(array_map('strval', $chain)) : [],
            'errorMessage' => $link['errorMessage'] ?? null,
            'isIgnored' => (bool) ($link['isIgnored'] ?? false),
            'dateIgnored' => self::date($link['dateIgnored'] ?? null),
            'lastCheckedAt' => self::date($link['lastCheckedAt'] ?? null),
            'firstBrokenAt' => self::date($link['firstBrokenAt'] ?? null),
            'sourceCount' => isset($link['sources']) && is_array($link['sources']) ? count($link['sources']) : (int) ($link['sourceCount'] ?? 0),
        ];

        if ($includeSources) {
            $data['sources'] = array_values(array_map(self::source(...), is_array($link['sources'] ?? null) ? $link['sources'] : []));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $source  A source's data, enriched by Reporting.
     * @return array{sourceType: string, entryId: int|null, siteId: int|null, entryTitle: string|null, entryStatus: string|null, fieldHandle: string|null, linkText: string|null, sourceUrl: string|null}
     */
    public static function source(array $source): array
    {
        return [
            'sourceType' => (string) $source['sourceType'],
            'entryId' => isset($source['entryId']) ? (int) $source['entryId'] : null,
            'siteId' => isset($source['siteId']) ? (int) $source['siteId'] : null,
            'entryTitle' => $source['entryTitle'] ?? null,
            'entryStatus' => $source['entryStatus'] ?? null,
            'fieldHandle' => $source['fieldHandle'] ?? null,
            'linkText' => $source['linkText'] ?? null,
            'sourceUrl' => $source['sourceUrl'] ?? null,
        ];
    }

    /**
     * A link's columns, with casts applied (dates as Carbon, `redirectChain` as a list).
     *
     * @return array<string, mixed>
     */
    public static function linkData(Link $link): array
    {
        $data = [];

        foreach (array_keys($link->getAttributes()) as $key) {
            $data[$key] = $link->getAttribute($key);
        }

        return $data;
    }

    /**
     * The control panel URL for a link's detail screen.
     */
    public static function linkCpUrl(int $linkId): string
    {
        return cp_url('appleseed/dashboard/detail/'.$linkId);
    }

    /**
     * ISO-8601, in the system time zone, or null.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = match (true) {
                $value instanceof CarbonInterface => $value,
                $value instanceof DateTimeInterface => Carbon::instance($value),
                default => Carbon::parse((string) $value, 'UTC')->setTimezone(date_default_timezone_get()),
            };
        } catch (Throwable) {
            return null;
        }

        return $date->toAtomString();
    }
}
