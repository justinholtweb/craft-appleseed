<?php

namespace justinholtweb\appleseed\migrations;

use craft\db\Migration;
use craft\db\Query;

/**
 * Removes link records whose URLs are unparsed element reference tags.
 *
 * Rich text values were previously read via getRawContent(), so links to entries and
 * assets were recorded as their literal reference tag resolved against the site base URL
 * (e.g. `https://example.com/{entry:123@1:url||https://example.com/page}`) and then
 * reported as broken. Those rows can never resolve, so drop them; the next scan records
 * the real URLs. Link sources are removed by the FK's ON DELETE CASCADE.
 */
class m260812_120000_purge_ref_tag_links extends Migration
{
    /** @var string Matches an element reference tag, e.g. `{entry:123@1:url||https://example.com/page}`. */
    private const REF_TAG_PATTERN = '/\{\w+\:[^\}]+\}/';

    public function safeUp(): bool
    {
        $candidates = (new Query())
            ->select(['id', 'url'])
            ->from('{{%appleseed_links}}')
            ->where(['like', 'url', '{'])
            ->all($this->db);

        $ids = [];
        foreach ($candidates as $candidate) {
            if (preg_match(self::REF_TAG_PATTERN, $candidate['url'])) {
                $ids[] = $candidate['id'];
            }
        }

        if (!empty($ids)) {
            $this->delete('{{%appleseed_links}}', ['id' => $ids]);
            echo '    > deleted ' . count($ids) . " link(s) with unparsed reference tags\n";
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260812_120000_purge_ref_tag_links cannot be reverted.\n";
        return false;
    }
}
