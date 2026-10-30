<?php

declare(strict_types=1);

namespace Preflow\Ai;

/** One request failed (timeout, bad answer, rate limit): callers may continue with the next one. */
final class AiRequestFailed extends \RuntimeException {}
