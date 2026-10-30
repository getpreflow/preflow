<?php

declare(strict_types=1);

namespace Preflow\Ai;

/** The provider cannot be used at all (no key, quota or billing problem, outage): callers stop the whole run. */
final class AiUnavailable extends \RuntimeException {}
