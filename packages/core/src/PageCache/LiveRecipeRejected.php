<?php

declare(strict_types=1);

namespace Preflow\Core\PageCache;

/**
 * Thrown by a live kind's renderer for a signed recipe that names no live part it declared (a
 * template outside its template folders, a block that is not a live block, a class not marked
 * #[Live]): the marker stays in the page as an inert HTML comment.
 */
final class LiveRecipeRejected extends \RuntimeException
{
}
