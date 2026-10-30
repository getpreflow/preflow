<?php

declare(strict_types=1);

namespace Preflow\Ai;

/** The answer arrived but cannot be used, e.g. it is not the JSON that was asked for. */
final class AiResponseInvalid extends \RuntimeException {}
