<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Fixtures\Pluggable;

/**
 * SignalDriver's recorded effects, one per render frame (a frame slot).
 *
 * @extends \ArrayObject<int, string>
 */
final class SignalEffects extends \ArrayObject {}
