<?php

namespace giantbits\htmx;

use Yii;
use yii\base\BootstrapInterface;
use yii\base\Application;
use yii\web\Application as WebApplication;

class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if (!($app instanceof WebApplication)) {
            return;
        }

        // Register the component controller route
        $app->controllerMap['htmx-component'] = [
            'class' => 'giantbits\htmx\controllers\ComponentController',
        ];

        // Add URL rule for clean component URLs
        $app->getUrlManager()->addRules([
            'htmx-component/render' => 'htmx-component/render',
        ], false);
    }
}
