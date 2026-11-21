<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

use Preflow\Core\Context\RenderContext;
use Preflow\Core\Context\RoleView;

/**
 * Asks, at every use of a preview token, whether the person signed in for the request may still
 * do what the token was issued for (PreviewToken::admit()): a role edited since, or a permission
 * taken away, ends the preview at its next request. Core binds DenyAllPreviewGate; preflow/auth
 * binds its Access-backed gate. Answer for $ctx's viewer only (the request's real one, never a
 * role view) and fail closed.
 *
 * @since 0.81.0
 */
interface PreviewGate
{
    /**
     * The permission to view the site as a role. A role view's token always requires it
     * (PreviewToken::issue() refuses one that does not), so allows() re-checks it at every use.
     */
    public const string VIEW_AS = 'site.view_as';

    /**
     * Whether $ctx's viewer holds every one of $permissions (false for none, or for an anonymous
     * or synthetic viewer).
     *
     * @param list<string> $permissions
     */
    public function allows(RenderContext $ctx, array $permissions): bool;

    /**
     * Whether $ctx's viewer may still view the site as $view by their roles (view as role).
     * Whether they may view as roles at all is VIEW_AS, which the token requires and allows() checks.
     */
    public function mayViewAs(RenderContext $ctx, RoleView $view): bool;
}
