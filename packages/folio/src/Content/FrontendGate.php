<?php

declare(strict_types=1);

namespace Preflow\Folio\Content;

use Preflow\Auth\Access\Access;
use Preflow\Auth\Access\UndeclaredPermission;

/**
 * Who may see a type gated by frontend.permission: only a signed-in user who
 * has the permission. Fails closed: nobody without auth configured (where
 * Access allows everything) and nobody for an undeclared permission (which
 * throws in debug). The frontend pages and folio_list/folio_find both ask here.
 */
final class FrontendGate
{
    public function __construct(
        private readonly ?Access $access,
        private readonly bool $debug = false,
    ) {}

    public function allows(?string $permission): bool
    {
        if ($permission === null) {
            return true;
        }
        $registry = $this->access?->registry();
        $declared = $registry !== null && ($registry->isDeclared($permission) || $registry->disabledReason($permission) !== null);
        if (!$declared) {
            if ($this->debug) {
                throw UndeclaredPermission::named($permission);
            }

            return false;
        }

        return $this->access?->user() !== null && $this->access->canAny($permission);
    }

    /** A type without frontend.permission is open; a malformed one (never past boot) is closed. */
    public function allowsType(TypeListing $listing): bool
    {
        $permission = $listing->frontend['permission'] ?? null;

        return $permission === null || (is_string($permission) && $this->allows($permission));
    }
}
