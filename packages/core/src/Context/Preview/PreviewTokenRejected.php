<?php

declare(strict_types=1);

namespace Preflow\Core\Context\Preview;

/**
 * A preview token that grants nothing: why, as one of REASONS. The message names the reason
 * only, never the token, the session or the key, so it may be logged. The middleware that reads
 * tokens answers every rejection 403.
 *
 * - malformed: not a token of this format (too long, another version, bad base64url, a payload
 *   that is not exactly the claims with their types);
 * - signature: not signed with this app's preview key, or a claim was changed;
 * - session: signed for another session (another browser, or after sign-out or sign-in);
 * - expired: past its expiry, or claiming more than PreviewToken::MAX_TTL;
 * - identity: issued to someone other than who is signed in now;
 * - permission: the issuer may no longer do what the token was checked for (PreviewGate);
 * - disabled: the app key is empty or public, so no token is trusted.
 *
 * @since 0.81.0
 */
final class PreviewTokenRejected extends \RuntimeException
{
    public const array REASONS = ['malformed', 'signature', 'expired', 'session', 'identity', 'permission', 'disabled'];

    /** @throws \InvalidArgumentException for a reason not in REASONS */
    public function __construct(public readonly string $reason)
    {
        if (!in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException("Unknown preview token rejection reason '{$reason}'.");
        }
        parent::__construct("The preview token was rejected ({$reason}).");
    }
}
