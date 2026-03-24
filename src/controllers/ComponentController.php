<?php

namespace giantbits\htmx\controllers;

use Yii;
use yii\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use giantbits\htmx\ComponentToken;
use giantbits\htmx\HtmxComponent;

/**
 * Universal HTMX endpoint for all component requests.
 *
 * Every HtmxComponent action routes through this single controller.
 * The token parameter carries the signed component class, props, and action.
 */
class ComponentController extends Controller
{
    public $enableCsrfValidation = false;
    public $layout = false;

    public function actionRender(): string
    {
        $request = Yii::$app->request;

        $token = $request->get('token') ?? $request->post('token');
        if (empty($token)) {
            throw new BadRequestHttpException('Missing component token.');
        }

        // Decode and verify the HMAC-signed token
        $decoded = ComponentToken::decode($token);

        $className = $decoded['class'];
        $props = $decoded['props'];
        $action = $decoded['action'];

        // Validate that the class exists and is an HtmxComponent
        if (!class_exists($className) || !is_subclass_of($className, HtmxComponent::class)) {
            throw new BadRequestHttpException('Invalid component class.');
        }

        /** @var HtmxComponent $component */
        $component = new $className(['props' => $props]);

        // Dispatch the action (if not just a re-render)
        if ($action !== 'render') {
            $component->handleAction($action, $request->bodyParams);
        }

        Yii::$app->response->format = Response::FORMAT_HTML;

        return $component->renderFragment();
    }
}
