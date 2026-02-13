<?php

namespace justinholtweb\appleseed\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $status
 * @property string $type
 * @property int $totalLinksFound
 * @property int $totalLinksChecked
 * @property int $brokenCount
 * @property int $redirectCount
 * @property int $workingCount
 * @property string|null $startedAt
 * @property string|null $completedAt
 * @property int|null $entryId
 */
class ScanRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%appleseed_scans}}';
    }
}
