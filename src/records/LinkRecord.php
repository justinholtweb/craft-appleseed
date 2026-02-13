<?php

namespace justinholtweb\appleseed\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $url
 * @property string $urlHash
 * @property int|null $statusCode
 * @property string $status
 * @property string|null $redirectUrl
 * @property string|null $redirectChain
 * @property string|null $errorMessage
 * @property string|null $lastCheckedAt
 * @property int|null $lastScanId
 * @property bool $isIgnored
 */
class LinkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%appleseed_links}}';
    }
}
