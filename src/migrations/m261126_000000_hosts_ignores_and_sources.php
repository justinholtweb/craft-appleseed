<?php

namespace justinholtweb\appleseed\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table;

/**
 * Appleseed 5.3.0.
 *
 * - `appleseed_links.host`, so the dashboard, GraphQL and MCP can filter and group by host.
 * - `appleseed_links.ignoredById` / `dateIgnored`: who ignored a link, and when.
 * - `appleseed_links.firstBrokenAt`: when a link started failing, so notifications can be limited
 *   to links that broke since the last scan. Backfilled from `lastCheckedAt` for broken links.
 * - `appleseed_scans.newBrokenCount`.
 * - Removes duplicate `appleseed_link_sources` rows. Before 5.3.0 every scan added a fresh copy of
 *   each source and nothing removed the old ones, so a link found once showed up as found many
 *   times on its detail screen and in the CSV.
 */
class m261126_000000_hosts_ignores_and_sources extends Migration
{
    public function safeUp(): bool
    {
        $links = '{{%appleseed_links}}';

        if (!$this->db->columnExists($links, 'host')) {
            $this->addColumn($links, 'host', $this->string(255)->after('urlHash'));
            $this->createIndex(null, $links, 'host');
        }

        if (!$this->db->columnExists($links, 'ignoredById')) {
            $this->addColumn($links, 'ignoredById', $this->integer()->after('isIgnored'));
            $this->addForeignKey(null, $links, 'ignoredById', Table::USERS, 'id', 'SET NULL');
        }

        if (!$this->db->columnExists($links, 'dateIgnored')) {
            $this->addColumn($links, 'dateIgnored', $this->dateTime()->after('ignoredById'));
        }

        if (!$this->db->columnExists($links, 'firstBrokenAt')) {
            $this->addColumn($links, 'firstBrokenAt', $this->dateTime()->after('lastCheckedAt'));
        }

        if (!$this->db->columnExists('{{%appleseed_scans}}', 'newBrokenCount')) {
            $this->addColumn('{{%appleseed_scans}}', 'newBrokenCount', $this->integer()->notNull()->defaultValue(0)->after('brokenCount'));
        }

        $this->_backfillHosts();
        $this->_dedupeSources();

        // Links already broken count as broken since they were last checked, so the first scan
        // after the update doesn't report every one of them as newly broken.
        $this->update('{{%appleseed_links}}', [
            'firstBrokenAt' => new \yii\db\Expression('[[lastCheckedAt]]'),
        ], [
            'and',
            ['status' => ['broken', 'dns_error', 'timeout', 'server_error']],
            ['firstBrokenAt' => null],
            ['not', ['lastCheckedAt' => null]],
        ], [], false);

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261126_000000_hosts_ignores_and_sources cannot be reverted.\n";

        return false;
    }

    private function _backfillHosts(): void
    {
        $query = (new Query())
            ->select(['id', 'url'])
            ->from('{{%appleseed_links}}')
            ->where(['host' => null]);

        foreach ($query->batch(500, $this->db) as $rows) {
            foreach ($rows as $row) {
                $host = parse_url((string)$row['url'], PHP_URL_HOST);

                if (is_string($host) && $host !== '') {
                    $this->update('{{%appleseed_links}}', ['host' => strtolower($host)], ['id' => $row['id']], [], false);
                }
            }
        }
    }

    private function _dedupeSources(): void
    {
        $query = (new Query())
            ->select(['id', 'linkId', 'entryId', 'siteId', 'fieldHandle', 'sourceType', 'sourceUrl'])
            ->from('{{%appleseed_link_sources}}')
            ->orderBy(['id' => SORT_DESC]);

        $seen = [];
        $duplicates = [];

        foreach ($query->each(1000, $this->db) as $row) {
            $key = implode("\0", [
                $row['linkId'],
                $row['entryId'] ?? '',
                $row['siteId'] ?? '',
                $row['fieldHandle'] ?? '',
                $row['sourceType'],
                $row['sourceUrl'] ?? '',
            ]);

            if (isset($seen[$key])) {
                $duplicates[] = (int)$row['id'];
            } else {
                $seen[$key] = true;
            }
        }

        foreach (array_chunk($duplicates, 1000) as $ids) {
            $this->delete('{{%appleseed_link_sources}}', ['id' => $ids]);
        }

        if ($duplicates !== []) {
            echo '    > removed ' . count($duplicates) . " duplicate link source(s)\n";
        }
    }
}
