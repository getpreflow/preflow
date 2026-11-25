<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ParentRef;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\StateRefused;
use Preflow\Core\Context\RefusedInPreview;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\View\AssetCollector;

final class ComponentEndpoint
{
    /**
     * The body field a call may send its token in, instead of the URL's `token` (which is read
     * first). Every driver shares it: htmx sends a post call's token here (in hx-vals), so large
     * props never make a long URL. Not `token`: htmx passes an element's hx-vals to every element
     * inside it and appends them to a get request's query, where the URL's own `token` must stay
     * the one that counts.
     *
     * @since 0.88.0
     */
    public const string TOKEN_FIELD = '_token';

    /**
     * The fields of a 0.86 call: read only for a token from ComponentToken::encodeFor(); never an
     * old-style action's params (a call around its element may have given them: htmx merges the
     * hx-vals of every ancestor).
     */
    private const array NEUTRAL_FIELDS = [
        DrivesComponentActions::VALUES_FIELD => true,
        DrivesComponentActions::REGION_FIELD => true,
        self::TOKEN_FIELD => true,
    ];

    /** The request fields the framework reads itself; never part of an action's params. */
    private const array OWN_FIELDS = [
        'token' => true,
        self::TOKEN_FIELD => true,
        DrivesComponentActions::STATE_FIELD => true,
        DrivesComponentActions::VALUES_FIELD => true,
        DrivesComponentActions::REGION_FIELD => true,
    ];

    /** @var callable(string, array): Component */
    private $componentFactory;

    /**
     * @param callable(string $class, array $props): Component $componentFactory
     */
    public function __construct(
        private readonly ComponentToken $token,
        private readonly ComponentRenderer $renderer,
        private readonly HypermediaDriver $driver,
        callable $componentFactory,
        private readonly ?AssetCollector $assetCollector = null,
        private readonly bool $debug = false,
    ) {
        $this->componentFactory = $componentFactory;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The token: the URL's first (a get call's, an old page's; an element inside a post call
        // gets that call's body token too, from htmx's inherited hx-vals), then a post call's body
        // field (0.88), then the body `token` of a form written before 0.88.
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];
        $body = is_array($bodyParams) ? $bodyParams : [];
        $tokenString = $queryParams['token'] ?? $body[self::TOKEN_FIELD] ?? $body['token'] ?? null;

        if ($tokenString === null) {
            throw new SecurityException(sprintf(
                "Missing component token. It is expected as 'token' in the URL or as '%s' in the body; a call that sends it in the body may have lost it to hx-params (on the element or an ancestor) or to a request hook that removed it.",
                self::TOKEN_FIELD,
            ));
        }

        // Layer 1: Token signature verification
        $payload = $this->token->decode($tokenString, maxAge: 86400);

        // Layer 2: Class validation
        if (!class_exists($payload->componentClass)
            || !is_subclass_of($payload->componentClass, Component::class)) {
            throw new SecurityException(
                "Invalid component class [{$payload->componentClass}]."
            );
        }

        // Create component via factory (supports DI)
        $component = ($this->componentFactory)($payload->componentClass, $payload->props);

        // Layer 3: Action whitelist
        if ($payload->action !== 'render'
            && !in_array($payload->action, $component->actions(), true)) {
            throw new SecurityException(
                "Action [{$payload->action}] is not allowed on [{$payload->componentClass}]."
            );
        }

        // Layer 4: Component-level guards, for actions and plain renders alike
        if ($component instanceof Guarded) {
            $component->authorize($payload->action, $request);
        }

        // A child rendered alone that sits inside a parent on the page: its template can call the parent.
        if ($payload->parentClass !== null && $payload->neutralCall) {
            $this->placeInParent($component, $payload->parentClass, $payload->parentKey);
        }

        // The client swaps the response into another element than the component: no wrapper
        $target = $this->driver->fragmentTarget($request);
        $isFragmentRequest = $target !== null && $target !== ''
            && $target !== $component->getComponentId();

        // The neutral fields of a 0.86 call (DrivesComponentActions); a get call sends them in the query.
        $fields = array_replace($queryParams, is_array($bodyParams) ? $bodyParams : []);
        if ($component->hasState()) {
            $html = $this->handleStateful($component, $payload, $fields, $isFragmentRequest);
        } elseif ($payload->neutralCall) {
            $html = $this->handleNeutralCall($component, $payload->action, $fields, $isFragmentRequest);
        } elseif ($payload->action !== 'render') {
            try {
                // Resolve state first so the action operates on correct base state
                $component->resolveState();
                // An old-style call: the body as in 0.85, without the values and region a 0.86 call
                // around the element may have given it (htmx merges hx-vals of every ancestor).
                $params = array_diff_key(is_array($bodyParams) ? $bodyParams : [], self::NEUTRAL_FIELDS);
                $component->handleAction($payload->action, $params);
                // Render without re-calling resolveState so action side-effects are preserved
                $html = $isFragmentRequest
                    ? $this->renderer->renderResolvedFragment($component)
                    : $this->renderer->renderResolved($component);
            } catch (HttpException|RefusedInPreview $e) {
                throw $e; // a deliberate refusal (403, 404…, a write a preview refuses) stays an HTTP error
            } catch (\Throwable $e) {
                // Like a render error: the boundary's fallback replaces the component (200, so htmx swaps it).
                $html = $this->renderer->renderError($component, $e, 'action');
            }
        } else {
            // Plain render — resolveState is called inside renderer
            $html = $isFragmentRequest
                ? $this->renderer->renderFragment($component)
                : $this->renderer->render($component);
        }

        return $this->respond($html);
    }

    /**
     * Tells the renderer that $component sits inside a $parentClass with key $parentKey on the
     * page. The class is checked here, before anything renders. The stand-in (built from class and
     * key, no mount(), no state) has the parent's id, so a `component: 'parent'` call in
     * $component's answer targets the parent on the page; it is built only when such a call (or
     * anything else) asks for the parent itself.
     */
    private function placeInParent(Component $component, string $parentClass, string|int|null $parentKey): void
    {
        if (!class_exists($parentClass) || !is_subclass_of($parentClass, Component::class)) {
            throw new SecurityException("Invalid parent component class [{$parentClass}].");
        }
        $this->renderer->placeInsideLazily(
            $component,
            new ParentRef($parentClass, $parentKey),
            fn (): Component => ($this->componentFactory)($parentClass, $parentKey === null ? [] : ['key' => $parentKey]),
        );
    }

    /** $html with the CSS/JS the render collected and the effects the action asked for. */
    private function respond(string $html): ResponseInterface
    {
        // Append any CSS/JS collected during the component render
        if ($this->assetCollector !== null) {
            $fragmentAssets = '';
            if ($this->assetCollector->hasCss()) {
                $fragmentAssets .= $this->assetCollector->renderCss();
            }
            if ($this->assetCollector->hasJs()) {
                $fragmentAssets .= $this->assetCollector->renderJsBody();
                $fragmentAssets .= $this->assetCollector->renderJsInline();
            }
            if ($fragmentAssets !== '') {
                $html .= $fragmentAssets;
            }
        }

        // The effects the action asked for (events, redirect, URL), as the driver's client reads them
        return $this->driver->applyEffects(
            new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html),
        );
    }

    /**
     * A component with #[State]: its state comes from the request (DrivesComponentActions::STATE_FIELD).
     * Without it, a token from ComponentToken::encodeFor() (key only) is refused like expired
     * state; an old-style token (all props) mounts from its props (a call written before the
     * component had state). Then the action (no resolveState()), or nothing for a refresh (no
     * mount()), then prepare() and the render: the whole component, or the region the call asked
     * for (REGION_FIELD). The new state goes back with the answer (stateUpdate()), unless the
     * action or the render failed.
     *
     * @param array<array-key, mixed> $fields the request's fields, the body over the query
     * @throws StateRefused state that is tampered, of another component, expired, of an older shape or missing (403)
     * @throws HttpException 400: a region the template does not have, an engine without regions, unreadable values
     */
    private function handleStateful(Component $component, TokenPayload $payload, array $fields, bool $isFragmentRequest): string
    {
        $action = $payload->action;
        $id = $component->getComponentId();
        $states = $fields[DrivesComponentActions::STATE_FIELD] ?? [];
        $carried = is_array($states) ? ($states[$id] ?? null) : null;
        if ($carried !== null && !is_string($carried)) {
            throw new StateRefused('Component state refused: not a state string.');
        }
        if ($carried === null && $payload->stateCarried) {
            // The token holds only the key: without its state there is nothing to mount from.
            throw new StateRefused('Component state missing.');
        }
        if ($carried !== null) {
            $this->renderer->restore($component, $carried); // StateRefused: 403, like a refused token
        }
        // Checked before the action runs: a bad region or bad values change nothing. An old-style
        // token's request has neither (values or a region there came from a 0.86 call around it).
        $region = $payload->neutralCall ? $this->requestedRegion($component, $fields[DrivesComponentActions::REGION_FIELD] ?? null) : null;
        $params = $payload->neutralCall ? $this->params($fields) : array_diff_key($fields, self::OWN_FIELDS);

        if ($carried === null) {
            try {
                $component->mount(); // no state on the page: an old-style call, from the token's props
            } catch (HttpException|RefusedInPreview $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->renderer->renderError($component, $e, 'mount'); // no new state goes back
            }
        }
        if ($action !== 'render') {
            try {
                $component->handleAction($action, $params);
            } catch (HttpException|RefusedInPreview $e) {
                throw $e;
            } catch (\Throwable $e) {
                // The boundary's fallback replaces the component (200); no new state goes back with it.
                return $this->renderer->renderError($component, $e, 'action');
            }
        }

        if ($region === null && $isFragmentRequest) {
            // An old-style call that swaps the answer into another element: no wrapper.
            try {
                $component->prepare();
            } catch (HttpException|RefusedInPreview $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->renderer->renderError($component, $e, 'prepare');
            }
            $html = $this->renderer->renderResolvedFragment($component);
        } else {
            $html = $this->renderer->renderAfterAction($component, $region);
        }

        // The page's carrier gets the new state, also when only a region was swapped. A render
        // that failed (its boundary's fallback) kept no new state: nothing goes back.
        $driver = $this->driver instanceof LazyHypermediaDriver ? $this->driver->driver() : $this->driver;
        $kept = $driver instanceof DrivesComponentActions ? $this->renderer->stateFor($component) : null;

        return $kept === null ? $html : $html . $driver->stateUpdate($id, $kept);
    }

    /**
     * A 0.86 call (a token from ComponentToken::encodeFor()) on a component without #[State]: as an
     * old-style call (resolveState(), then the action), with the values under the form's fields
     * as the action's params and the region it asked for rendered alone.
     *
     * @param array<array-key, mixed> $fields the request's fields, the body over the query
     * @throws HttpException 400: a region the template does not have, an engine without regions, unreadable values
     */
    private function handleNeutralCall(Component $component, string $action, array $fields, bool $isFragmentRequest): string
    {
        $region = $this->requestedRegion($component, $fields[DrivesComponentActions::REGION_FIELD] ?? null);
        $params = $this->params($fields);
        try {
            $component->resolveState();
        } catch (HttpException|RefusedInPreview $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->renderer->renderError($component, $e, 'resolveState');
        }
        if ($action !== 'render') {
            try {
                $component->handleAction($action, $params);
            } catch (HttpException|RefusedInPreview $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->renderer->renderError($component, $e, 'action');
            }
        }

        return $region === null && $isFragmentRequest
            ? $this->renderer->renderResolvedFragment($component)
            : $this->renderer->renderAfterAction($component, $region);
    }

    /**
     * An action's params: the values the call sent (VALUES_FIELD: one JSON object, or fields)
     * under the request's other fields (a form's own fields win), without the framework's fields.
     * Input, never state.
     *
     * @param array<array-key, mixed> $fields
     * @return array<array-key, mixed>
     * @throws HttpException 400: values that are not a JSON object
     */
    private function params(array $fields): array
    {
        $values = $fields[DrivesComponentActions::VALUES_FIELD] ?? [];
        if (is_string($values)) {
            $values = json_decode($values, true, 64);
            if (!is_array($values)) {
                throw new HttpException(400, 'The action values are not readable.');
            }
        }

        return array_replace(is_array($values) ? $values : [], array_diff_key($fields, self::OWN_FIELDS));
    }

    /**
     * The region the call asked for, checked against the component's template: null for none.
     * A region it doesn't have, or an engine that renders no regions, answers 400; outside debug
     * with a message that names no template.
     */
    private function requestedRegion(Component $component, mixed $region): ?string
    {
        if ($region === null || $region === '') {
            return null;
        }
        $notRendered = null;
        try {
            $regions = $this->renderer->regionsOf($component);
        } catch (\LogicException $e) {
            // An engine that passes regions on (lazy, sharing) to an engine that renders none.
            $regions = null;
            $notRendered = $e;
        }
        if (is_string($region) && $regions !== null && in_array($region, $regions, true)) {
            return $region;
        }
        if (!$this->debug) {
            throw new HttpException(400, 'The action asked for a part of the component that does not exist.');
        }
        $template = $component->getTemplatePath();
        $reason = match (true) {
            !is_string($region) => sprintf('the region field is a %s, not a name', get_debug_type($region)),
            $notRendered !== null => 'the template engine renders no regions (' . rtrim($notRendered->getMessage(), '.') . ')',
            $regions === null => 'the template engine renders no regions',
            default => sprintf("the template %s has no region '%s' (%s)", $template, $region, $regions === [] ? 'it has no regions' : 'its regions: ' . implode(', ', $regions)),
        };

        throw new HttpException(400, sprintf('The action on %s asked for a region it cannot render: %s.', $component::class, $reason), $notRendered);
    }
}
