<?php

declare(strict_types=1);

namespace Preflow\Core\Context;

/**
 * What happens to the side effects a render queues (mail, cookies, after-response work).
 *
 * @since 0.81.0
 */
enum OutboxPolicy
{
    /** Sent as usual. */
    case Deliver;
    /** Dropped. */
    case Discard;
    /** Kept for the caller to show (a preview lists the mail it would send), never sent. */
    case Capture;
}
