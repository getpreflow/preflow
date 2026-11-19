<?php

declare(strict_types=1);

namespace Preflow\Core\Http;

use Preflow\Core\Context\OutboxChannel;

/**
 * The outbox channel 'cookies': QueuedCookie items a render left queued. The owner of a request
 * (Application::run(), or handle() without a frame around it) takes them out of its frame and sets
 * them on the response before it is sent. What reaches this channel's deliver() was queued after
 * the response went out (work after the response): it can no longer be set and is dropped, with
 * a log line.
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
