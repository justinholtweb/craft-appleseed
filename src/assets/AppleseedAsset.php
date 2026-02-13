<?php

namespace justinholtweb\appleseed\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class AppleseedAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/appleseed.css',
        ];

        $this->js = [
            'js/appleseed.js',
        ];

        parent::init();
    }
}
