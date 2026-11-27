<?php

namespace justinholtweb\appleseed\helpers;

use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use justinholtweb\appleseed\records\ScanRecord;

/**
 * The shapes Appleseed's links and scans take outside the control panel: MCP tool results,
 * GraphQL, and the webhook body. One place, so they agree.
 */
final class Presenter
{
    /**
     * @param array<string, mixed>|ScanRecord $scan
     * @return array<string, mixed>
     */
    public static function scan(array|ScanRecord $scan): array
    {
        $scan = $scan instanceof ScanRecord ? $scan->getAttributes() : $scan;

        return [
            'id' => (int)$scan['id'],
            'type' => (string)$scan['type'],
            'status' => (string)$scan['status'],
            'totalLinksFound' => (int)$scan['totalLinksFound'],
            'totalLinksChecked' => (int)$scan['totalLinksChecked'],
            'brokenCount' => (int)$scan['brokenCount'],
            'newBrokenCount' => (int)($scan['newBrokenCount'] ?? 0),
            'redirectCount' => (int)$scan['redirectCount'],
            'workingCount' => (int)$scan['workingCount'],
            'entryId' => isset($scan['entryId']) ? (int)$scan['entryId'] : null,
            'startedAt' => self::date($scan['startedAt'] ?? null),
            'completedAt' => self::date($scan['completedAt'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $link A row from `appleseed_links`, optionally with enriched `sources`.
     * @return array<string, mixed>
     */
    public static function link(array $link, bool $includeSources = true): array
    {
        $chain = $link['redirectChain'] ?? null;
        if (is_string($chain) && $chain !== '') {
            $chain = Json::decodeIfJson($chain);
        }

        $data = [
            'id' => (int)$link['id'],
            'url' => (string)$link['url'],
            'host' => $link['host'] ?? LinkUrl::host((string)$link['url']),
            'status' => (string)$link['status'],
            'isBroken' => LinkStatus::isBroken($link['status'] ?? null),
            'statusCode' => isset($link['statusCode']) ? (int)$link['statusCode'] : null,
            'redirectUrl' => $link['redirectUrl'] ?? null,
            'redirectChain' => is_array($chain) ? array_values(array_map('strval', $chain)) : [],
            'errorMessage' => $link['errorMessage'] ?? null,
            'isIgnored' => (bool)($link['isIgnored'] ?? false),
            'dateIgnored' => self::date($link['dateIgnored'] ?? null),
            'lastCheckedAt' => self::date($link['lastCheckedAt'] ?? null),
            'firstBrokenAt' => self::date($link['firstBrokenAt'] ?? null),
            'sourceCount' => isset($link['sources']) ? count($link['sources']) : (int)($link['sourceCount'] ?? 0),
        ];

        if ($includeSources) {
            $data['sources'] = array_map([self::class, 'source'], $link['sources'] ?? []);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $source A row from `appleseed_link_sources`, enriched by Reporting.
     * @return array<string, mixed>
     */
    public static function source(array $source): array
    {
        return [
            'sourceType' => (string)$source['sourceType'],
            'entryId' => isset($source['entryId']) ? (int)$source['entryId'] : null,
            'siteId' => isset($source['siteId']) ? (int)$source['siteId'] : null,
            'entryTitle' => $source['entryTitle'] ?? null,
            'entryStatus' => $source['entryStatus'] ?? null,
            'fieldHandle' => $source['fieldHandle'] ?? null,
            'linkText' => $source['linkText'] ?? null,
            'sourceUrl' => $source['sourceUrl'] ?? null,
        ];
    }

    /**
     * The control panel URL for a link's detail screen.
     */
    public static function linkCpUrl(int $linkId): string
    {
        return UrlHelper::cpUrl('appleseed/dashboard/detail/' . $linkId);
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $date = DateTimeHelper::toDateTime($value);

        return $date ? DateTimeHelper::toIso8601($date) : null;
    }
}
