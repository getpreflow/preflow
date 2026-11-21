<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Preflow\Core\Context\RenderContext;

/**
 * The gate core binds: without a package that answers permissions (preflow/auth), nobody can be
 * checked, so no preview token is admitted.
 *
 * @since 0.81.0
 */
final class DenyAllPreviewGate implements PreviewGate
{
    public function allows(RenderContext $ctx, array $permissions): bool
    {
        return false;
    }
}
