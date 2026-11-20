<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\Contexts;
use Preflow\Notify\Mail\ComposeScope;

/**
 * Composes a mail in its recipient's context (core binds it when preflow/notify is installed):
 * the layout renders in a frame of its own under RenderContext::forRecipient($lang) (an anonymous
 * visitor, the public texts in $lang, published reads, no request), so a mail to a French reader
 * composed in a German admin request is French. The frame around it is untouched. Outside every
 * render it composes where it is called.
 *
 * @since 0.81.0
 */
final class NotifyComposeScope implements ComposeScope
{
    public function run(string $lang, \Closure $compose): mixed
    {
        $ctx = Contexts::peek();

        return $ctx === null ? $compose() : Contexts::run($ctx->forRecipient($lang), $compose);
    }
}
