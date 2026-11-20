<?php

declare(strict_types=1);

namespace Preflow\View;

/**
 * The CSP nonce of one render frame (a frame slot of NonceGenerator): made on first use, the one
 * a fragment request adopted from its page, or the nonce of the frame it shares ($from).
 *
 * @internal NonceGenerator
 * @since 0.81.0
 */
final class FrameNonce
{
    public ?string $value = null;

    /** Whether $value is the page nonce a fragment request sent back (untrusted input). */
    public bool $adopted = false;

    /** The frame nonce this one is, while it has no value of its own (a request inside a page). */
    public ?FrameNonce $from = null;

    /** The one that holds the value: this one, or the one it shares. */
    public function source(): self
    {
        $nonce = $this;
        while ($nonce->value === null && $nonce->from !== null) {
            $nonce = $nonce->from;
        }

        return $nonce;
    }
}
