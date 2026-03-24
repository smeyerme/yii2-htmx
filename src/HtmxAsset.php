<?php

namespace giantbits\htmx;

use yii\web\AssetBundle;
use yii\web\View;

/**
 * Asset bundle that registers HTMX from CDN.
 *
 * Register this in your layout or let HtmxComponent register it automatically.
 *
 * Configuration options (set via Yii::$app->params):
 *   'htmx.version' => '2.0.4'    // HTMX version to load
 *   'htmx.local'   => false      // Set true to use local copy instead of CDN
 */
class HtmxAsset extends AssetBundle
{
    public $sourcePath = '@giantbits/htmx/assets';

    public $jsOptions = [
        'position' => View::POS_HEAD,
    ];

    public function init(): void
    {
        parent::init();

        $version = \Yii::$app->params['htmx.version'] ?? '2.0.4';
        $useLocal = \Yii::$app->params['htmx.local'] ?? false;

        if ($useLocal) {
            $this->js = ['js/htmx.min.js'];
        } else {
            $this->js = ["https://unpkg.com/htmx.org@{$version}"];
            $this->sourcePath = null;
        }
    }
}
