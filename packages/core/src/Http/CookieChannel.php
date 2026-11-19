<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\OutboxChannel;

/**
 * The outbox channel 'cookies': the Set-Cookie values a capturing render (Application::render()
 * under OutboxPolicy::Capture, a preview) left queued, as QueuedCookie items, so its RenderResult
 * reports them by name. Application registers it on that frame only, and a capturing frame's
 * items never move on, so nothing is sent through it; a delivering frame's items reaching
 * deliver() could not be set on any response any more: they are dropped, with a log line.
 *
 * @since 0.81.0
 */
final class CookieChannel implements OutboxChannel
{
    public const string NAME = 'cookies';

    public function name(): string
    {
        return self::NAME;
    }

    public function deliver(array $items): void
    {
        $names = array_map(static fn (object $item): string => $item instanceof QueuedCookie ? $item->name() : get_debug_type($item), $items);
        error_log(sprintf(
            'Cookies queued after the response was sent cannot be set and were dropped: %s.',
            implode(', ', $names),
        ));
    }

    /** "Cookie 'name'": the name only, never the value. */
    public function describe(object $item): string
    {
        return $item instanceof QueuedCookie ? "Cookie '{$item->name()}'" : 'Cookie (' . get_debug_type($item) . ')';
    }
}
