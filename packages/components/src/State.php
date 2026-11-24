<?php

declare(strict_types=1);

namespace Preflow\Components;

/**
 * Marks a component property whose value the component keeps between its own actions.
 * Allowed types: scalars, null, arrays of those, backed enums and StateValue objects.
 *
 * @since 0.86.0
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class State {}
