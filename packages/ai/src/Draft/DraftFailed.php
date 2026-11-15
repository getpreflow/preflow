<?php

declare(strict_types=1);

namespace Preflow\Ai\Draft;

/**
 * This draft could not be written: no answer, an answer cut off, refused or empty. The message
 * says why in plain words. Unlike AiUnavailable, trying again may work.
 *
 * @since 0.77
 */
final class DraftFailed extends \RuntimeException {}
