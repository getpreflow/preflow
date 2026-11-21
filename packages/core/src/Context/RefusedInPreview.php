<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * Something a preview never does (change data, sign in or out, open a members-only part as a
 * visitor), refused: the error handler answers it 403 and shows its message, which is written
 * for the person looking at the preview (no internals). WritesRefused is one; auth's
 * PreviewRefusal another.
 *
 * @since 0.81.0
 */
interface RefusedInPreview extends \Throwable {}
