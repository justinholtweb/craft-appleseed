<?php

namespace justinholtweb\appleseed\events;

use justinholtweb\appleseed\records\ScanRecord;
use yii\base\Event;

/**
 * Triggered after a scan completes (`Scanner::EVENT_AFTER_SCAN`).
 */
class ScanEvent extends Event
{
    public ScanRecord $scan;
}
