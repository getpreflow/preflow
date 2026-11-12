<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * The header a 404 carries when it is an image variant that is allowed but
 * not made yet (preflow/media sends it), so the audit can tell it from a
 * missing page.
 */
final class VariantPending
{
    public const string HEADER = 'X-Preflow-Variant';
    public const string VALUE = 'pending';
}
