<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

use Preflow\Core\Context\Contexts;
use Preflow\Notify\Mail\ComposeScope;

/**
 * Composes a mail in its recipient's context (core binds it when preflow/notify is installed):
 * the layout renders in a frame of its own under RenderContext::forRecipient() (an anonymous
 * visitor, the public texts in the reader's language, published reads, no request), so a mail to
 * a French reader composed in a German admin request is French. The frame around it is
 * untouched: what the layout collects (assets, page meta) stays in the mail's frame, and what it
 * queues (a cookie, mail, after-response work) is dropped, since a mail's render has no response
 * of its own and must not add to the request's. A mail without a language is for a reader of the
 * current render's locale ('en' outside every render, where it is composed where it is called).
 *
 * @since 0.81.0
 */
final class NotifyComposeScope implements ComposeScope
{
    public function run(?string $lang, \Closure $compose): mixed
    {
        $ctx = Contexts::peek();
        $lang ??= $ctx?->locale ?? 'en';

        return $ctx === null ? $compose($lang) : Contexts::run($ctx->forRecipient($lang)->discarding(), static fn (): mixed => $compose($lang));
    }
}
