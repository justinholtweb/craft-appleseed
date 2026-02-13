<?php

namespace justinholtweb\appleseed\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $linkId
 * @property int|null $entryId
 * @property int|null $siteId
 * @property string|null $fieldHandle
 * @property string|null $linkText
 * @property string $sourceType
 * @property string|null $sourceUrl
 */
class LinkSourceRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%appleseed_link_sources}}';
    }
}
