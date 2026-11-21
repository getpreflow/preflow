<?php

declare(strict_types=1);

namespace Preflow\Auth;

use Preflow\Core\Context\RefusedInPreview;

/**
 * What a preview never does with accounts (view as role, a page rendered under a preview token):
 * sign in or out, since the session the request carries is the editor's, and let a visitor's view
 * into a part only signed-in people see. Answered 403 with its message (RefusedInPreview), which
 * is written for the editor looking at the preview. A LogicException, as the refusal it replaces.
 *
 * @since 0.81.0
 */
final class PreviewRefusal extends \LogicException implements RefusedInPreview
{
    public const string SIGN_IN = 'This preview does not sign in or out: it shows the site as the role you chose, not as you.';

    public const string SIGNED_OUT = 'This part is only for signed-in people. To see it in the preview, choose a role under View as.';

    /** Signing in or out inside a preview. */
    public static function signIn(): self
    {
        return new self(self::SIGN_IN);
    }

    /** A part behind a sign-in, asked for in a preview that is not signed in with that guard. */
    public static function signedOut(): self
    {
        return new self(self::SIGNED_OUT);
    }
}
