<?php

declare(strict_types=1);

namespace Preflow\Data;

/**
 * A change-date source could not answer (ChangeDateBackfill): the table waits for the next run.
 *
 * @internal
 * @since 0.84.0
 */
final class ChangeDateSourceFailed extends \RuntimeException {}
