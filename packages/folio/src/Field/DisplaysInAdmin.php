<?php

declare(strict_types=1);

namespace Preflow\Folio\Field;

/**
 * Capability for field types whose admin display (list cells, read-only fields) differs
 * from their frontend output, e.g. a formatted date. The frontend renderFrontend() stays as it is.
 */
interface DisplaysInAdmin
{
    /** Escaped HTML (or plain escaped text) of the value for the admin. */
    public function renderAdmin(mixed $value, array $config): string;
}
