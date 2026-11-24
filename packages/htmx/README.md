# preflow/htmx

Hypermedia abstraction and component endpoint for Preflow. Ships an HTMX driver out of the box; the `HypermediaDriver` interface allows swapping in alternatives (e.g. Datastar).

## Installation

```bash
composer require preflow/htmx
```

Requires PHP 8.4+, `ext-sodium`. Twig integration requires `twig/twig ^3.0`.

## What it does

`ComponentToken` issues HMAC-SHA256-signed tokens that encode a component class, props, and action name. `ComponentEndpoint` handles incoming hypermedia requests through five security layers: token verification → class validation → action whitelist → `Guarded` interface → dispatch. `HtmxDriver` generates `hx-*` attributes and sets response headers. The `hd` Twig global surfaces all of this as template helpers.

Since 0.86 a template calls an action by its name with neutral options (no `hx-` attribute), an
action can render only one region of its component, and a component's `#[State]` travels with its
actions: see "Calling actions from a template" below, and preflow/components' README for state.

## API

### `HtmxDriver`

Generates HTML attributes and sets response headers.

```php
// Attributes
$driver->actionAttrs(method: 'post', url: $url, targetId: $id, swap: SwapStrategy::OuterHTML): HtmlAttributes
$driver->listenAttrs(event: 'itemSaved', url: $url, targetId: $id): HtmlAttributes

// Response headers (call from within an action)
$driver->triggerEvent('itemSaved')    // HX-Trigger
$driver->redirect('/dashboard')      // HX-Redirect
$driver->pushUrl('/posts/1')         // HX-Push-Url

// Requests and responses (0.65; the component endpoint and core call these)
$driver->fragmentTarget($request)    // the HX-Target id, or null
$driver->applyEffects($response)     // the recorded HX-* headers, plus HX-Nonce
$driver->isFragmentRequest($request) // HX-Request: true
$driver->isHistoryRestore($request)  // HX-History-Restore-Request: true
$driver->varyHeaders()               // HX-Request, HX-Target, HX-History-Restore-Request
$driver->navigate($response, $url)   // HX-Redirect: $url, or HX-Refresh: true for null
$driver->announceNonce($response, $n)// HX-Nonce: $n (nonceHeaders(): HX-Nonce)
$driver->ownedHeaders()              // HX-* (OwnsResponseHeaders, 0.81): a page or component may not set these as response hints
$driver->pageNonce($request)         // null: htmx re-stamps scripts with the page nonce itself
```

### Another client library: `app.hypermedia` (0.65)

`HtmxDriver` implements `HypermediaDriver`, and an app has one driver: `config/app.php`
`'hypermedia'` names the class of another `HypermediaDriver` (default `HtmxDriver`), and the
container builds it (constructor parameters autowired, e.g. the `AssetCollector`). It is bound as
`HypermediaDriver`, and everything uses that binding: the `hd.*` helpers, `ComponentEndpoint`,
components that type-hint `HypermediaDriver` or the class, and the form helpers when the driver
also implements `Preflow\View\FormHypermediaDriver` (otherwise forms stay plain). Core also adds
it to `HypermediaProtocols` (preflow/core): auth, the expired-session answer, the security headers,
the page cache and GEO ask it about requests (see core's README, "Hypermedia clients").

`HypermediaDriver` is a `LazyHypermediaDriver` (`LazyFormHypermediaDriver` for a form driver)
standing in for the driver, which is the container's shared `$class`. Core builds it eagerly at the
end of the boot, right after the service providers ran (on the CLI too), so a driver that can't be
built stops the boot ("app.hypermedia: could not build …") instead of a later request. Its
constructor may therefore depend only on services bound by then: core's and those the providers
bind (a provider may bind `$class` itself), not on anything bound later (in a request, or by
middleware).
With another driver htmx isn't inlined (`config/htmx.php` then does nothing); the driver's
`assetTag()` or your layout loads its client.

```php
// config/app.php
'hypermedia' => App\Hypermedia\DatastarDriver::class,
```

Besides the attribute methods and `triggerEvent()` / `redirect()` / `pushUrl()`, a driver
implements `fragmentTarget(ServerRequestInterface): ?string` (the id of the element the client
swaps the response into; the endpoint renders the component without its wrapper when that is
another element), `applyEffects(ResponseInterface): ResponseInterface` (the recorded effects as
the client reads them: headers, or events in the body; called on every endpoint response) and
core's `HypermediaProtocol`: `isFragmentRequest()`, `isHistoryRestore()`, `varyHeaders()`,
`navigate()`, `announceNonce()` / `nonceHeaders()` and `pageNonce()`. Since 0.81 a driver may also
implement core's optional `OwnsResponseHeaders::ownedHeaders()` (the response headers that are the
client's, which `ResponseHeaderHints` then refuses; a trailing `*` is a prefix). Without it only its
nonce headers are reserved, so pages may set its effect headers. `pageNonce()` (a client that sends its
page's CSP nonce back with a fragment request is answered with it, so its effect scripts pass the
page's policy). `pageNonce()` returns untrusted input: core adopts it for fragment requests only
(not plain requests or history restores), for that response only, never stores the response,
and the driver must not print the nonce into the page — its client reads `script.nonce` at
request time. Since 0.81 a driver keeps what `triggerEvent()`, `redirect()` and `pushUrl()`
record per render frame (a frame slot, as `ResponseHeaders` does): an effect recorded in one
render never reaches another render's response. An unknown class throws at boot. Check a driver with
`Preflow\Testing\Contract\HypermediaDriverContract` (preflow/testing).

### A driver that runs component actions: `DrivesComponentActions` (0.86)

The short call (`hd.post('search', {}, {target: 'results'})`, below) names no client library. A
driver turns it into its own markup, and it carries a component's state with each action. That is
the optional interface `Preflow\Core\Http\Hypermedia\DrivesComponentActions`, next to
`Preflow\Htmx\HypermediaDriver` (which is unchanged). `HtmxDriver` implements it.

A driver without it still serves every old-style call (`hd.post('x', componentClass, componentId,
props)`). A short call under such a driver fails when the template renders, with a message naming
the driver, so state is never lost silently.

**What a new driver implements:**

| Method | What it returns | htmx |
|---|---|---|
| `callAttributes(ActionCall $call): array` | The attributes (name => unescaped value) of an element that runs `$call`: method and URL, target and swap, trigger, the call's values, the target region, and what makes it send the component's state (when `$call->carriesState`, 0.87: a component without state has no carrier on the page, so none is named). One request per component at a time: a second waits for the first, or with `latest` replaces it. | `hx-post`, `hx-target`, `hx-swap`, `hx-trigger`, `hx-include` (the state carrier first; `unset` when there is nothing to include, so an ancestor's `hx-include` doesn't reach the call), `hx-sync`, `hx-vals`, `hx-disabled-elt`, `hx-disinherit` |
| `listenAttributes(ActionCall $call, string $event): array` | `callAttributes()` with `$event` (fired anywhere on the page by an action's answer) as the only trigger. `$call` is the component's `render` call, with its state. | `hx-trigger="{event} from:body"` |
| `stateCarrier(string $componentId, string $carried): string` | The markup that carries a component's state on the page. The renderer puts it first inside the component's wrapper. It belongs to no form of the page. | a hidden input `preflow-state--{componentId}` with `form="preflow-no-form"` |
| `stateUpdate(string $componentId, string $carried): string` | The markup appended to an action's answer so the page's carrier holds the new state, also when the answer replaced only a region. | the carrier with `hx-swap-oob="true"` |

`ActionCall` (core) holds `method` (`get` or `post`), `url` (the endpoint with the action's
token), `componentId` (whose state goes along), `targetId` (the element the answer goes into: the
component or one of its regions), `options` (`ActionOptions`, the checked neutral options),
`values` and `carriesState` (0.87, default true: the component has a state carrier on the page; a
component without `#[State]` has none, so its calls send no state). `ActionOptions::driverOptions($name)` gives what a template put under the driver's own
key (the escape hatch, below); `drivers()` names every key given, so a driver can warn about keys
not its own.

**The request fields are the same for every driver,** so the component endpoint reads them
without knowing the client:

| Field | Holds |
|---|---|
| `_state[<componentId>]` (`STATE_FIELD`) | the state string the page carries for that component |
| `_values` (`VALUES_FIELD`) | the values the call sends: one JSON object as a string, or fields `_values[<name>]`. Unsigned input; the fields of the form the element sits in win over them |
| `_region` (`REGION_FIELD`) | the region the answer renders; empty or missing for the whole component |

**The busy class.** While a request runs, the element that sent it carries the class
`preflow-busy` (`BUSY_CLASS`), so CSS for "loading" names no client library. A driver adds and
removes it in its client (htmx: a few lines of script that `HtmxAssets` adds to a page with a
short call).

`HypermediaDriverContract` checks a driver that implements the interface (and skips those tests
for one that doesn't): every neutral option and the values change the call's attributes, the
listen attributes name the event, the carrier and the update hold the state string.

### `ComponentToken`

```php
$token->encode(componentClass: Post::class, props: ['id' => 1], action: 'save'): string
$token->decode(tokenString: $str, maxAge: 86400): TokenPayload
$token->encodeFor(component: $component, action: 'save'): string // 0.86: the short call's token
```

Tokens are URL-safe base64 strings. `maxAge` (seconds) enforces expiry. `encodeFor()` makes one
token per action and component, whatever the values: for a component with state it carries only
the `key` prop (the rest lives on as state).

### `ComponentEndpoint`

Universal PSR-7 handler. Mount it at e.g. `/--component/action` and `/--component/render`.

```php
$endpoint->handle(ServerRequestInterface $request): ResponseInterface
```

Security layers applied on every request:
1. Token present and base64-decodable
2. HMAC-SHA256 signature valid, optional max-age enforced
3. `componentClass` is a real subclass of `Component`
4. `action` is in `$component->actions()` (or `'render'`)
5. If component implements `Guarded`, `authorize(action, request)` is called

Since 0.86, for a component with `#[State]`: the state the request carries (`_state[<id>]`) is
restored from its store before the action; tampered, foreign, expired or missing state answers
403 (`StateRefused`), like a refused token. A region the request names (`_region`) is checked
against the component's template before the action runs; an unknown one answers 400 (in debug the
message names the template's regions). The answer carries the new state (`stateUpdate()`), unless
the action or the render failed.

### `Guarded` interface

Add component-level authorization without middleware.

```php
interface Guarded
{
    public function authorize(string $action, ServerRequestInterface $request): void;
}
```

Throw `ForbiddenHttpException` to deny access.

### `SwapStrategy` enum

`OuterHTML`, `InnerHTML`, `BeforeBegin`, `AfterBegin`, `BeforeEnd`, `AfterEnd`, `Delete`, `None`

### Calling actions from a template (0.86)

#### The problem it solves

Before 0.86 every action call repeated the component's class and id, its props and `| raw`, and
passed htmx attributes (`'hx-target'`, `'hx-trigger'`, `'hx-sync'`) straight in. Preflow promises
that htmx is one driver and another can take its place; a template full of `hx-` options breaks
that promise.

#### The short call

Inside a component's template, call an action by its name, with only the values that change:

```twig
<button {{ hd.post('addRow') }}>Add another</button>
<a href="{{ nextUrl }}" {{ hd.post('page', { page: next }, { component: 'parent', target: 'results', scroll: 'top' }) }}>Next</a>
```

| Helper | What it prints |
|---|---|
| `hd.post(action, values = {}, options = {})` | an element that runs `action` with a POST |
| `hd.get(action, values = {}, options = {})` | the same with a GET. On a component with state the state then travels in the address, which has a length limit: prefer `post` |
| `hd.on(event, options = {})` | an element that renders the component again when an action's answer fires `event` |
| `hd.region(name)` | `id="{componentId}-{name}"`, for an element made by hand that a call targets |
| `hd.hasParent()` | not markup: `true` when the component has a parent on the page (see "Calling the parent") |

The component is the one whose template is rendering: no class, no id. The helpers return markup
marked safe, so no `| raw`. In Blade the same calls are `{{ $hd->post('addRow') }}`.

The `values` travel unsigned, with the fields of the form the element sits in (a form field wins
over a value of the same name). The action gets all of it as `$params`, which is input to check;
only the component's state is trusted (preflow/components' README, "`$params` is input").

The starter's search form (`packages/starter/app/Components/JobSearch/JobSearch.twig`): a change
runs `search` at once, typing runs it once the visitor pauses, and only the results come back, so
the search box keeps its focus:

```twig
<form class="job-search__form" method="get" action="/jobs"
    {{ hd.post('search', {}, { target: 'results', on: ['change', 'submit'], latest: true }) }}>
    <input class="job-search__q" type="search" name="q" value="{{ filters.q }}"
        {{ hd.post('search', {}, { target: 'results', on: 'input', debounce: 300, latest: true }) }}>
    …
    {% region 'results' { class: 'job-search__results' } %}
        {{ component('JobResults', { jobs: jobs, total: total, view: filters.view }) }}
        {{ component('Pager', { lastPage: lastPage, query: filters.toQuery() }) }}
    {% endregion %}
</form>
```

#### Regions: an action renders only the part it changes

A region is a named part of a component's template:

```twig
{% region 'results' { class: 'job-search__results' } %}…{% endregion %}
```

```blade
@region('results', ['class' => 'job-search__results']) … @endregion
```

- On a page it renders in place, inside `<div id="{componentId}-{name}">` with the attributes you
  give (the attributes may be left out; `id` is the region's own).
- A call with `target: 'results'` renders **only that region** on the server (after the action
  and `prepare()`), and the driver swaps it in. By default it replaces the region's contents
  (`swap: 'inner'`); the region element stays on the page. The new state still reaches the page.
- A region sees only the component's own variables (its public properties, `componentId`,
  `props`…), not variables set with `{% set %}` (Blade: `@php`) earlier in the template, because it
  must render the same on its own as in place. A Twig component template that `extends` a layout
  with regions is the one exception that looks like it works: the child's top-level `{% set %}`
  reaches the layout's regions on a page render, but not when the region renders alone. Don't rely
  on it.
- Names are a letter, then letters, digits, `-` and `_`, unique in the template. Regions don't
  nest.
- Only regions in the component's own template file can be targeted. A region in an included
  partial is not found, and a region inside a loop prints the same id many times: keep target
  regions at the top level of the template.
- A region named `parent` cannot be targeted (`target: 'parent'` is refused; the parent's action is
  `component: 'parent'`).
- A call that names a region the template doesn't have answers 400 (in debug the message lists the
  regions). So does any region under a template engine without regions (`RendersRegions`).
- Blade finds `@region` also inside `{{-- --}}` comments and `@verbatim`: don't write the word there
  with parentheses after it.

A region replaces flags like a `resultsOnly` prop that told the template to print only a part.

#### Options

A small, fixed set of words that every driver translates (`Preflow\Core\Http\Hypermedia\ActionOptions`):

| Option | Values | Meaning | htmx |
|---|---|---|---|
| `target` | `'self'` (default), a region name | where the answer goes | `hx-target` |
| `swap` | for `self`: `'outer'` (default), `'delete'`, `'none'`; for a region: `'inner'` (default), `'append'`, `'prepend'`, `'none'` | how it goes in | `hx-swap` |
| `on` | `'click'`, `'submit'`, `'change'`, `'input'`, `'load'`, `'visible'`, `'blur'` (0.87), or a list | the events that send the request; none: the element's usual one. `visible`: the element scrolls into view; `blur`: the field is left (check a field once the visitor moves on) | `hx-trigger` (`visible` is `revealed`) |
| `debounce` | milliseconds | wait this long after the last typing (`input`) event; clicks and changes go at once. Only with `input` in `on` | `delay:300ms` |
| `latest` | `true` | a newer request replaces one still running (for searches) | `hx-sync` `replace` |
| `include` | a region or field name, or a list | extra fields to send | `hx-include` |
| `disable` | `'self'`, a region name | what can't be used while the request runs. `'self'` (0.87): the element if it is a button, and its submit controls (a form's buttons whose type is submit, i.e. none or `submit`, and `input` type `submit` or `image`, also those linked with `form="…"`; not `type="button"` or `"reset"`), from the moment the call is triggered, so a second click or Enter sends nothing, even while the request waits for another request of the component. A region (0.87): the region's `input`, `select`, `textarea` and `button` elements, from when the request is sent | `hx-disabled-elt` (`this`, or the region), plus `data-preflow-disable` (`submits`, or `fields #<region id>`) read by a small preflow script |
| `scroll` | `'top'` | show the top of the target after the swap | `show:…:top` |
| `component` | `'parent'` | run the action of the component whose template rendered this one | the parent's token and id |

- A value of the wrong kind always fails. An unknown option fails in debug with the list of known
  ones; outside debug it is left out with a log line, so the page keeps working. The same goes for
  `debounce` without `input` in `on`.
- **The swap fits the target.** The answer to `self` is the whole component, so it replaces the
  component (`outer`), removes it (`delete`) or is dropped (`none`). The answer to a region is the
  region's contents, so it replaces them (`inner`), goes after or before them (`append`,
  `prepend`) or is dropped (`none`). `append` and `prepend` are for a region that renders only the
  new items, e.g. "load more": the action renders the next page's rows, and they go below the rows
  already there. Another pair fails in debug and names the swaps that fit; outside debug it logs a
  line and uses the target's default.
- **Checking a field when it is left.** `on: 'blur'` sends the request when the field loses
  focus, so a message shows once the visitor moves on, not on every key:

  ```twig
  <input name="email" value="{{ email }}"
      {{ hd.post('checkEmail', {}, { target: 'emailHint', on: 'blur', include: 'email' }) }}>
  ```

- **Sending a form once.** `disable: 'self'` on a form disables its submit buttons until the
  answer is in (also after an error), so a second click or Enter starts no second request:

  ```twig
  <form {{ hd.post('send', {}, { disable: 'self' }) }}>
      <input name="email" {{ hd.post('checkEmail', {}, { target: 'emailHint', on: 'blur' }) }}>
      <textarea name="message"></textarea>
      <button>Send</button>
  </form>
  ```

  The buttons are disabled as soon as the form is sent, not only when its request leaves. Say the
  visitor types an address and double-clicks Send: leaving the field starts `checkEmail`, the
  first click makes `send` wait for it (one request per component at a time), and the second
  click finds the button disabled.

  htmx puts `disabled` on the form, but that does nothing on a `<form>` (or a `<div>`). What
  protects the buttons is a small preflow script, added under the page nonce, once per page, to
  pages with a `disable` call. It enables again only what it disabled: a button the page had
  disabled stays disabled. It enables them again when the request ends: after the answer (also
  when the answer replaced the form, so a button linked with `form="…"` outside it comes back), an
  error, an abort or a timeout. And when a waiting request is never sent (an `hx-confirm` answered
  with no, a validation error, the form gone from the page).

  A region works the same way for its fields, but they are disabled only once the request is sent:
  a waiting request still reads their values, and a disabled field sends none.

  With the htmx include mode `never` (a site brings its own htmx), the driver still writes the
  `data-preflow-disable` marker but adds no script, so only htmx's own `disabled` applies. Such a
  site adds its own script for the marker.

  Known limits:
  - A confirm dialog of your own (a handler that cancels `htmx:confirm` and calls
    `issueRequest()` later) is outside the waiting rule: while it is open the buttons are enabled,
    and once it answers yes they are disabled from when the request is sent.
  - An htmx extension whose `onEvent` returns false for `htmx:confirm` stops the request in a way
    the script can't see. If another request of the component was running at that moment, the
    buttons stay disabled until the page reloads.
  - Some browsers drop the focus from a button when it is disabled. htmx then forgets which button
    was clicked, so a form that waited for another request may arrive without that button's
    `name`/`value`. Don't rely on the clicked button's value for a form with `disable: 'self'`.

- **One action at a time per component.** Requests of one component wait for each other, so two
  quick clicks on "Add another" give two rows. `latest: true` opts into "a newer request cancels
  the older one".
- **Calling the parent.** `component: 'parent'` runs the parent's action with the parent's state;
  `target` then names a region of the parent. The starter's `Pager` sits inside `JobSearch`'s
  `results` region and calls its `page` action:

  ```twig
  <a class="pager__link" href="{{ nextUrl }}" rel="next"
      {{ hd.post('page', { page: next }, { component: 'parent', target: 'results', scroll: 'top' }) }}>…</a>
  ```

  It also works for a child rendered inside an action's answer, and in the child's own answer:
  when the child's action or `hd.on` refresh renders the child alone, its token named the parent
  (class and key), so the call still goes to the parent on the page, with the parent's state. That
  needs a parent that keeps state (`#[State]`): a parent without state needs all its props in its
  token, and they aren't known when the child renders alone, so the call fails there (a
  `LogicException`; the child's error boundary shows its fallback). A component rendered on its own
  (by a page template) has no parent; the call then fails too.

  Since 0.87 the endpoint builds that parent (through the component factory) only when the child's
  answer calls it. Its own calls and `hd.hasParent()` need only the parent's class and key. So a
  parent whose class can't be built in the child's request fails only a `component: 'parent'` call.

  A component that is shown both inside a parent and on its own (say, a step in an editor's
  preview) asks first with `hd.hasParent()`. It is true inside another component's template, and
  when the child renders alone for its own action with a parent named in its token. On its own it
  is false, so the buttons render without calls:

  ```twig
  {% if hd.hasParent() %}
      <button {{ hd.post('answer', { choice: 'a' }, { component: 'parent' }) }}>A</button>
  {% else %}
      <button type="button">A</button>
  {% endif %}
  ```

  In Blade: `@if($hd->hasParent()) … @endif`. Like the other short calls it throws a
  `LogicException` outside a component's template.
- The set stays small: a new option comes only when at least two real templates need it.
  Everything else goes through the escape hatch.

#### The escape hatch: the driver's own attributes

What the neutral set doesn't cover goes under a key named after the driver:

```twig
<button {{ hd.post('removeRow', { row: row.id }, { target: 'rows', htmx: { 'hx-confirm': 'Remove this row?' } }) }}>Remove</button>
```

A template that does this is visibly tied to htmx. Another driver ignores the key (in debug it
logs one warning per key and request). The attributes are added as given, except the ones the call
needs for its state and target: `hx-include`, `hx-vals`, `hx-sync` and `hx-target` (also with
`data-`) fail in debug and are left out otherwise; `hx-disinherit` is always the call's own.

#### The busy class

While an action runs, the element that sent it carries the class `preflow-busy`, so CSS for
"loading" names no client library. htmx's own `htmx-request` class stays as it was. The starter
dims its results while a search or a page runs:

```css
.job-search__form:is(.preflow-busy, :has(.preflow-busy)) .job-search__results { opacity: 0.6; }
```

#### Quick clicks inside a region that gets replaced

A click on an element inside the part the previous answer replaces is dropped: the element is gone
before its request could leave. That is safe (a "Remove" by position would hit the wrong row), but
plan for it:

- Put "Add" buttons **outside** the region they add to.
- Give rows **stable keys** (an id in the state), not positions.

```twig
{% region 'rows' %}
    {% for row in rows %}
        <input name="rows[{{ row.id }}][name]" value="{{ row.name }}">
        <button {{ hd.post('removeRow', { row: row.id }, { target: 'rows' }) }}>Remove</button>
    {% endfor %}
{% endregion %}
<button {{ hd.post('addRow', {}, { target: 'rows' }) }}>Add another</button>
```

```php
#[State] public array $rows = [];

public function actionRemoveRow(array $params): void
{
    $id = (string) ($params['row'] ?? '');   // input: checked against the state
    $this->rows = array_values(array_filter($this->rows, fn (array $row): bool => $row['id'] !== $id));
}
```

#### Refreshing on an event

An action fires an event with `$hd->triggerEvent('cartChanged')`; another component listens:

```twig
<div {{ hd.on('cartChanged', { target: 'summary' }) }}></div>
```

The refresh sends the component's state (as a POST), so it renders as the page has it: no
`mount()` again, only `prepare()`. `target` names a region to render alone; `on` is not allowed
here (the event is the trigger).

#### Before 0.86

The old form, `hd.post('increment', componentClass, componentId, props, swap, extra)` (and
`hd.get`, `hd.on('itemSaved', componentClass, componentId, props)`), keeps working unchanged and
is not deprecated; it is recognised by its second argument, a class name. Components without
`#[State]` behave exactly as in 0.85 with either form. An old-style call on a component with state
sends no state: the component mounts from the props in the call again. A short call inside an
old-style call's element inherits that element's htmx attributes the short call doesn't set
(`hx-confirm`, `hx-indicator`, …), as any htmx element does.

`{{ hd.assetTag() }}` marks htmx as needed on the page.

#### Locale prefix

Since 0.46, `HtmxExtensionProvider` takes an optional `pathPrefix` closure (`\Closure(): string`) that is put before every URL it builds (`actionUrl`, `post`, `get`, `on`). Preflow's core passes the locale prefix the current request's URL carried (`Contexts::current()->request->localePrefix`; `LocaleMiddleware::urlPrefix()` before 0.83), so on a `/de/…` page component URLs are `/de/--component/…` and the action runs in German; the locale middleware strips `/de` again before the endpoint. Pages without a prefix, and apps without the i18n URL prefix strategy, keep `/--component/…`. A hand-written `hx-post="/--component/…"` loses the locale, so use `hd.actionUrl()`. `HtmxExtensionProvider` (the `hd.*` helpers in core apps) applies the prefix. Folio's admin preview under `/folio` carries no locale prefix, so it renders components without it. Error pages are rendered after the pipeline (the kernel catches the exception once the locale middleware has returned), so a component on a 404 or 500 page under `/de/…` gets an unprefixed `/--component/…` URL and its action runs in the default locale.

```php
new HtmxExtensionProvider($driver, $token, pathPrefix: fn (): string => Contexts::peek()?->request?->localePrefix ?? '');
```

## Usage

**Component with an action:**

```php
use Preflow\Components\Component;

final class Counter extends Component
{
    public int $count = 0;

    public function resolveState(): void
    {
        $this->count = (int) ($_SESSION['count'] ?? 0);
    }

    public function actions(): array
    {
        return ['increment'];
    }

    public function actionIncrement(array $params): void
    {
        $this->count++;
        $_SESSION['count'] = $this->count;
    }
}
```

**Template** (`Counter.twig`):

```twig
<p>Count: {{ count }}</p>
<button {{ hd.post('increment') }}>+1</button>
```

The counter keeps its number in the session because it must outlive the page. A number that only
this view needs (a page of results, the rows of a form) is `#[State]` instead: see preflow/components.

**Guarded component:**

```php
use Preflow\Htmx\Guarded;
use Preflow\Core\Exceptions\ForbiddenHttpException;
use Psr\Http\Message\ServerRequestInterface;

final class AdminPanel extends Component implements Guarded
{
    public function actions(): array
    {
        return ['deleteItem'];
    }

    public function authorize(string $action, ServerRequestInterface $request): void
    {
        $user = $request->getAttribute('user');
        if (!$user?->isAdmin()) {
            throw new ForbiddenHttpException('Admins only.');
        }
    }

    public function actionDeleteItem(array $params): void
    {
        // ...
    }
}
```

**Wire up the endpoint:**

```php
use Preflow\Htmx\{ComponentEndpoint, ComponentToken, HtmxDriver, ResponseHeaders};

$token    = new ComponentToken(secretKey: $_ENV['APP_KEY']);
$headers  = new ResponseHeaders();
$driver   = new HtmxDriver($headers);
$endpoint = new ComponentEndpoint(
    token: $token,
    renderer: $renderer,
    driver: $driver,
    componentFactory: fn (string $class, array $props) => new $class(),
);

// Route POST /--component/action and GET /--component/render to:
$response = $endpoint->handle($request);
```

**Add asset tag in your base layout:**

```twig
{{ hd.assetTag() }}
```

### Asset injection in fragment responses

`ComponentEndpoint` automatically appends any CSS and JS collected during component rendering to HTMX fragment responses. Styles and scripts defined inside a component with `{% apply css %}` / `{% apply js %}` are included even when the response is a partial swap — no separate asset pipeline step needed.

### Fragment rendering

When the driver's `fragmentTarget()` (htmx: the `HX-Target` header) does not match the component's own ID, `ComponentEndpoint` returns a fragment response via `renderFragment()` / `renderResolvedFragment()` rather than the full wrapped component. This lets a single component handle both full-page renders and targeted partial swaps.

`ComponentRenderer::renderResolvedFragment()` skips `resolveState()` and renders inner HTML only — used after an action has already mutated state.
