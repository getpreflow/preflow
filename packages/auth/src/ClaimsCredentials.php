<?php

declare(strict_types=1);

namespace Preflow\Auth;

/**
 * Optional for a UserProviderInterface: it knows the identity in the credentials (an e-mail) even
 * when it will not resolve that user (a disabled account). A ChainUserProvider stops at the first
 * provider that claims the credentials, so a disabled account is not rescued by a later provider.
 */
interface ClaimsCredentials
{
    /** @param array<string, mixed> $credentials */
    public function claims(array $credentials): bool;
}
