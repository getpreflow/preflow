<?php

declare(strict_types=1);

namespace Preflow\Core\Http\Hypermedia;

/**
 * One element that runs a component action: what a driver that drives component actions
 * (DrivesComponentActions::callAttributes()) needs to write its attributes.
 *
 * @since 0.86.0
 */
final readonly class ActionCall
{
    /**
     * The request body field a call may send its token ($token) in, instead of the URL's `token`
     * (which the component endpoint reads first). Shared by every driver, beside
     * DrivesComponentActions::STATE_FIELD, VALUES_FIELD and REGION_FIELD: htmx sends a post call's
     * token here (in hx-vals), so large props never make a long URL. Not `token`: htmx passes an
     * element's hx-vals to every element inside it and appends them to a get request's query,
     * where the URL's own `token` must stay the one that counts. Not `_token` either: sites use
     * that name for their own form tokens.
     *
     * @since 0.88.0
     */
    public const string TOKEN_FIELD = '_component_token';

    /**
     * @param string $method `get` or `post`
     * @param string $url the component endpoint's URL with the action's token (also when $token is
     *        set: a driver written before 0.88 uses it as it is)
     * @param string $componentId the component whose action runs: its state goes with the request
     * @param string $targetId the element the answer goes into (the component, its parent, or a region's element)
     * @param array<string, mixed> $values sent unsigned in the request body under
     *        DrivesComponentActions::VALUES_FIELD; the action receives them as input, beside the form's fields
     * @param bool $carriesState whether the component carries state on the page (#[State]: its
     *        stateCarrier() is in its wrapper and goes with the request). False: there is no
     *        carrier to send, so the driver names none (@since 0.87.0)
     * @param string|null $token the action's signed token, the one $url carries: a driver may send
     *        it in the request body instead of the URL (field TOKEN_FIELD, `_component_token`; the
     *        URL's `token` is read first), so large props never make a URL too long. Null: only $url has it (@since 0.88.0)
     * @param string|null $endpointUrl $url without the token (with every other parameter it has,
     *        e.g. a preview's): the URL a driver writes when it sends $token in the body. Given
     *        together with $token (@since 0.88.0)
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $componentId,
        public string $targetId,
        public ActionOptions $options,
        public array $values = [],
        public bool $carriesState = true,
        public ?string $token = null,
        public ?string $endpointUrl = null,
    ) {
        if ($method !== 'get' && $method !== 'post') {
            throw new \InvalidArgumentException(sprintf("An action call's method is 'get' or 'post', not '%s'.", $method));
        }
        if (($token === null) !== ($endpointUrl === null)) {
            throw new \InvalidArgumentException(sprintf(
                "An action call's token and endpointUrl go together: %s is given without %s. Give both (the token and the URL without it) or neither (the URL with the token is enough).",
                $token === null ? 'endpointUrl' : 'token',
                $token === null ? 'token' : 'endpointUrl',
            ));
        }
    }
}
