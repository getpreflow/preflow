<?php

declare(strict_types=1);

namespace Preflow\Htmx;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Preflow\Components\Component;
use Preflow\Components\ComponentRenderer;
use Preflow\Components\StateRefused;
use Preflow\Core\Context\RefusedInPreview;
use Preflow\Core\Exceptions\HttpException;
use Preflow\Core\Exceptions\SecurityException;
use Preflow\Core\Http\Hypermedia\DrivesComponentActions;
use Preflow\View\AssetCollector;

final class ComponentEndpoint
{
    /** The request fields the framework reads itself; never part of an action's params. */
    private const array OWN_FIELDS = [
        'token' => true,
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
        // Extract token from query or body
        $queryParams = $request->getQueryParams();
        $bodyParams = $request->getParsedBody() ?? [];
        $tokenString = $queryParams['token'] ?? $bodyParams['token'] ?? null;

        if ($tokenString === null) {
            throw new SecurityException('Missing component token.');
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

        // The client swaps the response into another element than the component: no wrapper
        $target = $this->driver->fragmentTarget($request);
        $isFragmentRequest = $target !== null && $target !== ''
            && $target !== $component->getComponentId();

        // The neutral fields of a 0.86 call (DrivesComponentActions); a get call sends them in the query.
        $fields = array_replace($queryParams, is_array($bodyParams) ? $bodyParams : []);
        if ($component->hasState()) {
            $html = $this->handleStateful($component, $payload, $fields, $isFragmentRequest);
        } elseif (array_key_exists(DrivesComponentActions::VALUES_FIELD, $fields) || array_key_exists(DrivesComponentActions::REGION_FIELD, $fields)) {
            $html = $this->handleNeutralCall($component, $payload->action, $fields, $isFragmentRequest);
        } elseif ($payload->action !== 'render') {
            try {
                // Resolve state first so the action operates on correct base state
                $component->resolveState();
                $params = is_array($bodyParams) ? $bodyParams : [];
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
        // Checked before the action runs: a bad region or bad values change nothing.
        $region = $this->requestedRegion($component, $fields[DrivesComponentActions::REGION_FIELD] ?? null);
        $params = $this->params($fields);

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
     * A 0.86 call (it sends VALUES_FIELD or REGION_FIELD) on a component without #[State]: as an
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
        $regions = $this->renderer->regionsOf($component);
        if (is_string($region) && $regions !== null && in_array($region, $regions, true)) {
            return $region;
        }
        if (!$this->debug) {
            throw new HttpException(400, 'The action asked for a part of the component that does not exist.');
        }
        $template = $component->getTemplatePath();
        $reason = match (true) {
            !is_string($region) => sprintf('the region field is a %s, not a name', get_debug_type($region)),
            $regions === null => 'the template engine renders no regions',
            default => sprintf("the template %s has no region '%s' (%s)", $template, $region, $regions === [] ? 'it has no regions' : 'its regions: ' . implode(', ', $regions)),
        };

        throw new HttpException(400, sprintf('The action on %s asked for a region it cannot render: %s.', $component::class, $reason));
    }
}
