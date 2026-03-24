<?php

namespace giantbits\htmx;

use Yii;
use yii\base\Widget;
use yii\helpers\Html;
use ReflectionClass;

/**
 * Base class for HTMX-powered components.
 *
 * Each component is a self-contained unit with:
 * - Props (passed from parent, serialized into endpoint tokens)
 * - State (resolved from DB/session via resolveState())
 * - Actions (methods like actionToggle() called via HTMX)
 * - A co-located view file (ClassName.view.twig or .view.php)
 * - Automatic HTMX endpoint URL generation
 *
 * Usage:
 *   <?= MyComponent::widget(['props' => ['id' => 42]]) ?>
 *
 * Or in Twig:
 *   {{ use('app/components/MyComponent') }}
 *   {{ my_component_widget({'props': {'id': 42}}) }}
 */
class HtmxComponent extends Widget
{
    /** @var array Props passed from parent component */
    public array $props = [];

    /** @var string HTMX swap strategy for this component */
    public string $swapStrategy = 'outerHTML';

    /** @var string|null Explicit component ID. Auto-generated if null. */
    public ?string $componentId = null;

    /** @var string Tag name for the wrapper element */
    public string $tag = 'div';

    /** @var array Extra HTML attributes for the wrapper element */
    public array $wrapperOptions = [];

    /** @var bool Whether to send no-cache headers on HTMX responses */
    public bool $noCache = false;

    /** @var string|null Set by handleAction to override the default render behavior */
    protected ?string $actionSwap = null;

    /** @var string|null HTML to return instead of rendering the view (used by actions) */
    protected ?string $actionHtml = null;

    /** @var array Extra response headers to send (e.g., HX-Trigger) */
    protected array $responseHeaders = [];

    /** @var string Route to the component controller. Override if you customized the route. */
    public static string $controllerRoute = 'htmx-component/render';

    public function init(): void
    {
        parent::init();

        if ($this->componentId === null) {
            $this->componentId = $this->generateComponentId();
        }
    }

    /**
     * Main render entry point (called by Widget::widget()).
     */
    public function run(): string
    {
        // Register HTMX asset on full page loads
        if (!$this->isHtmxRequest()) {
            HtmxAsset::register($this->getView());
        }

        $this->resolveState();

        $innerHtml = $this->renderView();
        return $this->wrapHtml($innerHtml);
    }

    /**
     * Render just the component fragment (no layout). Used by ComponentController.
     */
    public function renderFragment(): string
    {
        // Send any response headers set by actions
        foreach ($this->responseHeaders as $name => $value) {
            Yii::$app->response->headers->set($name, $value);
        }

        // If an action set a special swap, communicate it
        if ($this->actionSwap === 'delete') {
            Yii::$app->response->headers->set('HX-Reswap', 'delete');
            return '';
        }

        if ($this->actionHtml !== null) {
            return $this->actionHtml;
        }

        $this->resolveState();

        $innerHtml = $this->renderView();
        return $this->wrapHtml($innerHtml);
    }

    /**
     * Override this to load state from DB/session before rendering.
     */
    protected function resolveState(): void
    {
        // Override in subclass
    }

    /**
     * Return a list of allowed action names.
     * @return string[]
     */
    protected function actions(): array
    {
        return [];
    }

    /**
     * Dispatch an action by name.
     */
    public function handleAction(string $action, array $params = []): void
    {
        if ($action === 'render') {
            return;
        }

        $allowed = $this->actions();
        if (!in_array($action, $allowed, true)) {
            throw new \yii\web\BadRequestHttpException("Unknown action: {$action}");
        }

        $method = 'action' . ucfirst($action);
        if (!method_exists($this, $method)) {
            throw new \yii\web\BadRequestHttpException("Action method not found: {$method}");
        }

        $this->$method($params);
    }

    // ─── HTMX Attribute Helpers ──────────────────────────────────────

    /**
     * Generate HTMX attributes for a GET request to this component.
     */
    public function hxGet(string $action = 'render', array $attrs = []): string
    {
        return $this->hxAttrs(array_merge([
            'hx-get' => $this->getEndpointUrl($action),
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ], $attrs));
    }

    /**
     * Generate HTMX attributes for a POST request to this component.
     */
    public function hxPost(string $action, array $attrs = []): string
    {
        return $this->hxAttrs(array_merge([
            'hx-post' => $this->getEndpointUrl($action),
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ], $attrs));
    }

    /**
     * Generate HTMX attributes for a DELETE request to this component.
     */
    public function hxDelete(string $action, array $attrs = []): string
    {
        return $this->hxAttrs(array_merge([
            'hx-delete' => $this->getEndpointUrl($action),
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ], $attrs));
    }

    /**
     * Generate HTMX attributes for a PUT request to this component.
     */
    public function hxPut(string $action, array $attrs = []): string
    {
        return $this->hxAttrs(array_merge([
            'hx-put' => $this->getEndpointUrl($action),
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ], $attrs));
    }

    /**
     * Generate HTMX attributes for a PATCH request to this component.
     */
    public function hxPatch(string $action, array $attrs = []): string
    {
        return $this->hxAttrs(array_merge([
            'hx-patch' => $this->getEndpointUrl($action),
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ], $attrs));
    }

    /**
     * Generate attributes to listen for a cross-component event and re-render.
     */
    public function hxTriggerListen(string $eventName, string $action = 'render'): string
    {
        return $this->hxAttrs([
            'hx-get' => $this->getEndpointUrl($action),
            'hx-trigger' => $eventName . ' from:body',
            'hx-target' => '#' . $this->componentId,
            'hx-swap' => $this->swapStrategy,
        ]);
    }

    /**
     * Set a response header (most commonly HX-Trigger for cross-component events).
     */
    protected function emitEvent(string $eventName): void
    {
        $this->responseHeaders['HX-Trigger'] = $eventName;
    }

    /**
     * Emit multiple events at once.
     */
    protected function emitEvents(array $eventNames): void
    {
        $this->responseHeaders['HX-Trigger'] = implode(', ', $eventNames);
    }

    /**
     * Redirect the client after an action via HTMX.
     */
    protected function redirect(string $url): void
    {
        $this->responseHeaders['HX-Redirect'] = $url;
    }

    /**
     * Push a URL to the browser history after an action.
     */
    protected function pushUrl(string $url): void
    {
        $this->responseHeaders['HX-Push-Url'] = $url;
    }

    // ─── URL Generation ──────────────────────────────────────────────

    /**
     * Get the HTMX endpoint URL for this component + action.
     */
    public function getEndpointUrl(string $action = 'render'): string
    {
        $token = ComponentToken::encode(static::class, $this->props, $action);

        return Yii::$app->urlManager->createUrl([static::$controllerRoute, 'token' => $token]);
    }

    // ─── Request Helpers ─────────────────────────────────────────────

    /**
     * Check if the current request is an HTMX request.
     */
    public function isHtmxRequest(): bool
    {
        return Yii::$app->request->headers->has('HX-Request');
    }

    /**
     * Check if this is a boosted request (hx-boost).
     */
    public function isBoostedRequest(): bool
    {
        return Yii::$app->request->headers->get('HX-Boosted') === 'true';
    }

    // ─── Rendering Internals ─────────────────────────────────────────

    /**
     * Render the co-located view file.
     */
    protected function renderView(): string
    {
        $viewFile = $this->getViewFile();

        return $this->getView()->renderFile($viewFile, [
            'component' => $this,
        ]);
    }

    /**
     * Resolve the co-located view file path.
     * Convention: ClassName.view.twig (or .view.php fallback) in the same directory as ClassName.php.
     */
    protected function getViewFile(): string
    {
        $reflection = new ReflectionClass($this);
        $dir = dirname($reflection->getFileName());
        $name = $reflection->getShortName();

        // Prefer .twig, fall back to .php
        $twigFile = $dir . '/' . $name . '.view.twig';
        if (file_exists($twigFile)) {
            return $twigFile;
        }

        return $dir . '/' . $name . '.view.php';
    }

    /**
     * Wrap the inner HTML with the component's container div.
     */
    protected function wrapHtml(string $innerHtml): string
    {
        $options = array_merge($this->wrapperOptions, [
            'id' => $this->componentId,
        ]);

        return Html::tag($this->tag, $innerHtml, $options);
    }

    /**
     * Generate a stable component ID from class name and props.
     */
    protected function generateComponentId(): string
    {
        $reflection = new ReflectionClass($this);
        $short = $reflection->getShortName();

        // Convert CamelCase to kebab-case
        $kebab = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $short));

        if (!empty($this->props)) {
            $hash = substr(md5(json_encode($this->props)), 0, 8);
            return $kebab . '-' . $hash;
        }

        return $kebab;
    }

    /**
     * Convert an array of HTML attributes to a string.
     */
    protected function hxAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $key => $value) {
            $parts[] = $key . '="' . Html::encode($value) . '"';
        }

        return implode(' ', $parts);
    }
}
