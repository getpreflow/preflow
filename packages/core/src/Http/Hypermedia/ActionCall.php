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
     * @param string $method `get` or `post`
     * @param string $url the component endpoint's URL with the action's token
     * @param string $componentId the component whose action runs: its state goes with the request
     * @param string $targetId the element the answer goes into (the component, its parent, or a region's element)
     * @param array<string, mixed> $values sent unsigned in the request body under
     *        DrivesComponentActions::VALUES_FIELD; the action receives them as input, beside the form's fields
     */
    public function __construct(
        public string $method,
        public string $url,
        public string $componentId,
        public string $targetId,
        public ActionOptions $options,
        public array $values = [],
    ) {
        if ($method !== 'get' && $method !== 'post') {
            throw new \InvalidArgumentException(sprintf("An action call's method is 'get' or 'post', not '%s'.", $method));
        }
    }
}
