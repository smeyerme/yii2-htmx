# yii2-htmx

HTMX-powered component framework for Yii2. Build interactive UIs with server-rendered components — no JavaScript framework, no build tools.

Each component is a self-contained class + template pair that automatically gets an HTMX endpoint. User interactions trigger server-side actions, and HTMX swaps the fresh HTML into the DOM. The developer experience is similar to React/Vue but everything runs server-side.

## Installation

```bash
composer require giantbits/yii2-htmx
```

The package auto-bootstraps via Yii2's extension system — no manual configuration needed. It registers:
- A `htmx-component/render` controller route (universal endpoint for all component requests)
- HTMX loaded from CDN

For Twig template support (recommended):

```bash
composer require yiisoft/yii2-twig:"~2.4"
```

And configure the renderer in your app config:

```php
'components' => [
    'view' => [
        'renderers' => [
            'twig' => [
                'class' => 'yii\twig\ViewRenderer',
                'cachePath' => '@runtime/Twig/cache',
                'options' => ['auto_reload' => true],
            ],
        ],
    ],
],
```

## Quick Example

A component is two files side by side:

```
components/
├── Counter.php
└── Counter.view.twig
```

**Counter.php**
```php
<?php
namespace app\components;

use giantbits\htmx\HtmxComponent;

class Counter extends HtmxComponent
{
    public int $count = 0;

    protected function actions(): array
    {
        return ['increment', 'decrement'];
    }

    protected function resolveState(): void
    {
        $this->count = Yii::$app->session->get('counter', 0);
    }

    public function actionIncrement(): void
    {
        $this->resolveState();
        Yii::$app->session->set('counter', ++$this->count);
    }

    public function actionDecrement(): void
    {
        $this->resolveState();
        Yii::$app->session->set('counter', --$this->count);
    }
}
```

**Counter.view.twig**
```twig
<div class="counter">
    <button {{ component.hxPost('decrement') | raw }}>-</button>
    <span>{{ component.count }}</span>
    <button {{ component.hxPost('increment') | raw }}>+</button>
</div>
```

**Use it anywhere:**
```php
<?= \app\components\Counter::widget() ?>
```

Or in Twig:
```twig
{{ use('app/components/Counter') }}
{{ counter_widget() }}
```

Click the buttons — the counter updates without a page reload. No JavaScript written.

## How It Works

```
User clicks [+] button
  → HTMX sends POST /htmx-component/render?token=<signed>
  → ComponentController decodes the HMAC-signed token
  → Extracts: {class: Counter, props: {}, action: increment}
  → Instantiates Counter, calls actionIncrement()
  → Counter re-renders with fresh state
  → Returns HTML fragment (no layout)
  → HTMX swaps old element with new one in the DOM
```

Every component instance gets a unique signed URL. The HMAC signature (using your app's `cookieValidationKey`) prevents tampering with class names, props, or actions.

## Creating Components

### The Component Class

Extend `HtmxComponent` and override what you need:

```php
<?php
namespace app\components;

use giantbits\htmx\HtmxComponent;

class JobFilter extends HtmxComponent
{
    public array $jobs = [];
    public array $filters = [];

    // Which actions can be called via HTMX
    protected function actions(): array
    {
        return ['filter', 'reset'];
    }

    // Load state before every render
    protected function resolveState(): void
    {
        $query = Job::find();
        if (!empty($this->props['category'])) {
            $query->andWhere(['category' => $this->props['category']]);
        }
        // Apply filters from POST data or session
        $this->filters = Yii::$app->session->get('jobFilters', []);
        foreach ($this->filters as $key => $value) {
            $query->andWhere([$key => $value]);
        }
        $this->jobs = $query->all();
    }

    public function actionFilter(array $params = []): void
    {
        $filters = Yii::$app->request->post();
        Yii::$app->session->set('jobFilters', $filters);
        // Component auto re-renders after the action
    }

    public function actionReset(): void
    {
        Yii::$app->session->remove('jobFilters');
    }
}
```

### The Template

Templates receive the component instance as `component`. Use the HTMX helpers to wire up interactions:

**Twig** (`.view.twig`):
```twig
<form {{ component.hxPost('filter') | raw }}>
    <select name="location">
        <option value="">All locations</option>
        <option value="remote">Remote</option>
        <option value="onsite">On-site</option>
    </select>
    <button type="submit">Filter</button>
    <button {{ component.hxPost('reset') | raw }}>Reset</button>
</form>

<div class="job-list">
    {% for job in component.jobs %}
        {{ use('app/components/JobCard') }}
        {{ job_card_widget({'props': {'id': job.id}}) }}
    {% endfor %}
</div>
```

**PHP** (`.view.php`) — also supported:
```php
<form <?= $component->hxPost('filter') ?>>
    <!-- ... -->
</form>
```

The base class prefers `.view.twig` and falls back to `.view.php`.

### Props

Props are passed from parent to child and serialized into the endpoint token. Use them as lightweight identifiers — not full data objects.

```twig
{{ job_card_widget({'props': {'id': job.id}}) }}
```

```php
<?= JobCard::widget(['props' => ['id' => $job->id]]) ?>
```

The component resolves the actual data in `resolveState()`:

```php
protected function resolveState(): void
{
    $this->job = Job::findOne($this->props['id']);
}
```

This keeps tokens small and data always fresh.

## HTMX Helpers

These generate the full set of HTMX attributes in one call — no manual URL or target wiring:

| Helper | Generated Attributes |
|--------|---------------------|
| `component.hxGet('render')` | `hx-get="..." hx-target="#id" hx-swap="outerHTML"` |
| `component.hxPost('action')` | `hx-post="..." hx-target="#id" hx-swap="outerHTML"` |
| `component.hxPut('action')` | `hx-put="..." ...` |
| `component.hxPatch('action')` | `hx-patch="..." ...` |
| `component.hxDelete('action')` | `hx-delete="..." ...` |
| `component.hxTriggerListen('event')` | `hx-get="..." hx-trigger="event from:body" ...` |

Override or add attributes with the second argument:

```twig
{{ component.hxPost('save', {'hx-swap': 'innerHTML', 'hx-indicator': '#spinner'}) | raw }}
```

In Twig, always pipe through `| raw` since the output is pre-escaped HTML attributes.

## Cross-Component Communication

Components communicate through HTMX events — decoupled, like a pub/sub system.

**Publisher** — emits an event after an action:
```php
public function actionAdd(): void
{
    // ... save to DB ...
    $this->emitEvent('jobListChanged');
}
```

**Subscriber** — listens and auto-refreshes:
```twig
<div {{ component.hxTriggerListen('jobListChanged') | raw }}>
    {% for job in component.jobs %}
        ...
    {% endfor %}
</div>
```

When the publisher's action completes, the `HX-Trigger` response header fires `jobListChanged`, and any element with `hx-trigger="jobListChanged from:body"` automatically re-fetches itself.

You can also emit multiple events:
```php
$this->emitEvents(['jobListChanged', 'statsUpdated']);
```

## Action Utilities

Use these inside `action*()` methods:

```php
// Remove element from DOM (e.g., after deleting a record)
$this->actionSwap = 'delete';

// Emit event for other components to react
$this->emitEvent('listChanged');

// Client-side redirect after action
$this->redirect('/some/url');

// Push URL to browser history
$this->pushUrl('/jobs?page=2');

// Set custom response HTML instead of re-rendering the template
$this->actionHtml = '<p>Custom response</p>';
```

## Component Properties

| Property | Type | Default | Description |
|----------|------|---------|-------------|
| `props` | `array` | `[]` | Data passed from parent, serialized into the endpoint token |
| `swapStrategy` | `string` | `'outerHTML'` | Default HTMX swap strategy |
| `componentId` | `?string` | auto | DOM ID. Auto-generated from class name + props hash |
| `tag` | `string` | `'div'` | Wrapper element tag |
| `wrapperOptions` | `array` | `[]` | Extra HTML attributes for wrapper |

## Configuration

Set these in `Yii::$app->params`:

```php
'params' => [
    'htmx.version' => '2.0.4',  // HTMX version to load from CDN
    'htmx.local'   => false,    // true = load from local asset instead of CDN
],
```

## PJAX Migration

If you're migrating from Yii2's built-in Pjax, the mapping is straightforward:

| Pjax Pattern | HTMX Component Equivalent |
|-------------|--------------------------|
| `Pjax::begin()` ... `Pjax::end()` | `MyComponent::widget()` |
| `$.pjax({url, container})` | `component.hxGet('render')` |
| `$.pjax.reload({container})` | `component.hxTriggerListen('event')` |
| PJAX container ID | `component.componentId` (auto-generated) |
| `pjax:complete` event | `HX-Trigger` response header + `hx-trigger` attribute |

Key advantages over Pjax:
- No jQuery dependency
- No custom `jquery.pjax.js` fixes needed
- Components are self-contained (class + template + actions in one place)
- Fine-grained updates (swap a single item, not an entire container)
- Built-in event system for cross-component communication

## Security

Component endpoint URLs contain HMAC-signed tokens. The token encodes the class name, props, and action, signed with your app's `cookieValidationKey`. This prevents:

- Instantiating arbitrary PHP classes
- Tampering with props
- Calling unregistered actions

Only actions listed in the `actions()` method can be invoked. The `ComponentController` verifies signatures before processing any request.

## Requirements

- PHP 8.1+
- Yii2 >= 2.0.45
- `yiisoft/yii2-twig` (optional, for `.twig` templates)
