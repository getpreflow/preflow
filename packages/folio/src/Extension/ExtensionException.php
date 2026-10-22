<?php

declare(strict_types=1);

namespace Preflow\Folio\Extension;

/** Invalid extension declarations or config/folio/extensions.json entries (raised at boot). */
final class ExtensionException extends \RuntimeException
{
}
