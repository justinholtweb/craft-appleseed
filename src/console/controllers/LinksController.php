<?php

namespace justinholtweb\appleseed\console\controllers;

use craft\console\Controller;
use justinholtweb\appleseed\helpers\LinkStatus;
use justinholtweb\appleseed\Plugin;
use justinholtweb\appleseed\records\LinkRecord;
use yii\console\ExitCode;

/**
 * Export and re-check links.
 */
class LinksController extends Controller
{
    /** @var string|null Only links with this status (`broken` for every broken status, or `ignored`). */
    public ?string $status = null;

    /** @var string|null Only links to this host. */
    public ?string $host = null;

    /** @var int|null Only links found on this site. */
    public ?int $siteId = null;

    /** @var string|null Write the CSV here instead of to stdout. */
    public ?string $file = null;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'export') {
            array_push($options, 'status', 'host', 'siteId', 'file');
        }

        return $options;
    }

    /**
     * Export links as CSV, one row per place each was found.
     *
     * Usage: craft appleseed/links/export [--status=broken] [--host=example.com] [--site-id=1] [--file=links.csv]
     */
    public function actionExport(): int
    {
        if ($this->status !== null && !in_array($this->status, LinkStatus::ALL, true)) {
            $this->stderr('Unknown status. Use one of: ' . implode(', ', LinkStatus::ALL) . "\n");
            return ExitCode::USAGE;
        }

        $csv = Plugin::getInstance()->reporting->generateCsv($this->status, array_filter([
            'host' => $this->host,
            'siteId' => $this->siteId,
        ]));

        if ($this->file) {
            file_put_contents($this->file, $csv);
            $this->stdout("Written to {$this->file}\n");
        } else {
            $this->stdout($csv);
        }

        return ExitCode::OK;
    }

    /**
     * Check one known link again now.
     *
     * Usage: craft appleseed/links/recheck <linkId>
     */
    public function actionRecheck(int $linkId): int
    {
        $link = LinkRecord::findOne($linkId);
        if (!$link) {
            $this->stderr("Link not found.\n");
            return ExitCode::DATAERR;
        }

        $result = Plugin::getInstance()->scanner->checkLink($link);
        $this->stdout("{$link->url}\n  Status: {$result->status}" . ($result->statusCode ? " ({$result->statusCode})" : '') . "\n");

        return $result->isBroken() ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
