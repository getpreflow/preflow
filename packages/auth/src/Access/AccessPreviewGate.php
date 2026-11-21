<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Core\Context\Contexts;
use Preflow\Core\Context\Preview\PreviewGate;
use Preflow\Core\Context\RenderContext;

/**
 * The preview gate auth binds (when config/auth.php exists): at every use of a preview token,
 * Access::can() for each permission the token requires (a role view's include site.view_as),
 * asked for the request's real viewer ($ctx's). A permission taken away or a role edited
 * since the token was issued (in the admin, or with staff:roles) ends the preview at its next
 * request.
 *
 * Fails closed: nobody signed in, a role view (a token is never checked against one), or no
 * permission to check answers false. An undeclared permission throws in debug, as can() does.
 *
 * @since 0.81.0
 */
final class AccessPreviewGate implements PreviewGate
{
    public function __construct(private readonly Access $access) {}

    public function allows(RenderContext $ctx, array $permissions): bool
    {
        if ($permissions === [] || !$this->real($ctx)) {
            return false;
        }

        return $this->as($ctx, function () use ($permissions): bool {
            foreach ($permissions as $permission) {
                if (!is_string($permission) || !$this->access->can($permission)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** A real person is signed in on $ctx (not nobody, not a role view), and the gate is not asked from inside a role view. */
    private function real(RenderContext $ctx): bool
    {
        return !$ctx->viewer->isAnonymous() && !$ctx->viewer->isSynthetic() && (Contexts::depth() === 0 || !Contexts::current()->viewer->isSynthetic());
    }

    /**
     * $ask with $ctx as the current context (Access reads the viewer from it): as it is when $ctx
     * is current, within() it in the current frame, or in a frame of its own outside every render.
     *
     * @param \Closure(): bool $ask
     */
    private function as(RenderContext $ctx, \Closure $ask): bool
    {
        if (Contexts::depth() === 0) {
            return Contexts::run($ctx, $ask);
        }

        return Contexts::current() === $ctx ? $ask() : Contexts::within($ctx, $ask);
    }
}
