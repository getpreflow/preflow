<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Bridge;

/**
 * A message Notifier::send() did not deliver because the render captures (a preview, a role
 * view): kept in the frame's outbox (channel 'notify') only to be listed. It is never sent.
 *
 * @since 0.81.0
 */
final readonly class RecordedSend
{
    public function __construct(public string $summary) {}
}
