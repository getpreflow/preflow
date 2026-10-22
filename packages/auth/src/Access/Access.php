<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;

/**
 * The one permission question: can('page.publish'). It asks about the user
 * signed in for this request, in its population (staff or members, per guard),
 * and lets the installed resolver answer. Undeclared permissions throw in
 * debug and are denied (logged once) in production; so are
 * those of a disabled extension.
 */
final class Access
{
    private PermissionResolver $resolver;

    /** @var array<string, true> undeclared permissions already logged this request */
    private array $logged = [];

    /** @var array<string, true> guards marked as staff by a package (Folio) */
    private array $staffGuards = [];

    /**
     * @param array<string, string> $populations guard name => 'staff'|'members' from config/auth.php
     */
    public function __construct(
        private readonly ?AuthManager $auth,
        private readonly PermissionRegistry $registry,
        private readonly bool $debug,
        private readonly array $populations,
    ) {
        $this->resolver = new DefaultResolver();
    }

    public function setResolver(PermissionResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** A package that authenticates staff with this guard (Folio) marks it; config still wins. */
    public function markStaffGuard(string $guard): void
    {
        $this->staffGuards[$guard] = true;
    }

    /** @return 'staff'|'members' */
    public function populationOf(string $guard): string
    {
        $configured = $this->populations[$guard] ?? null;
        if ($configured === 'staff' || $configured === 'members') {
            return $configured;
        }

        return isset($this->staffGuards[$guard]) ? 'staff' : 'members';
    }

    public function can(string $permission, ?object $subject = null): bool
    {
        $early = $this->earlyAnswer($permission);
        if ($early !== null) {
            return $early;
        }
        $user = $this->auth?->user();
        if ($user === null) {
            return false;
        }

        return $this->resolver->allows($user, $this->populationOf($this->auth->guardName() ?? ''), $permission, $subject);
    }

    /**
     * Like can(), but any user signed in for this request counts, across guards (a staff
     * editor who is also a signed-in member passes by the member's permission).
     */
    public function canAny(string $permission, ?object $subject = null): bool
    {
        $early = $this->earlyAnswer($permission);
        if ($early !== null) {
            return $early;
        }
        foreach ($this->auth?->users() ?? [] as $guard => $user) {
            if ($this->resolver->allows($user, $this->populationOf((string) $guard), $permission, $subject)) {
                return true;
            }
        }

        return false;
    }

    /** The answer that holds whoever is signed in (disabled, no auth, undeclared), or null to ask the user. */
    private function earlyAnswer(string $permission): ?bool
    {
        $disabled = $this->registry->disabledReason($permission);
        if ($disabled !== null) { // before the undeclared check; a disabled name never throws, not even in debug
            if (!isset($this->logged[$permission])) {
                $this->logged[$permission] = true;
                error_log("preflow/auth: permission {$permission} belongs to a disabled {$disabled}");
            }

            return false;
        }
        if ($this->auth === null) {
            return true; // no auth configured: nothing to restrict (e.g. Folio without login)
        }
        if (!$this->registry->isDeclared($permission)) {
            if ($this->debug) {
                throw UndeclaredPermission::named($permission);
            }
            if (!isset($this->logged[$permission])) {
                $this->logged[$permission] = true;
                error_log("preflow/auth: undeclared permission \"{$permission}\" denied");
            }

            return false;
        }

        return null;
    }

    /** The user signed in for this request; null when nobody is, or no auth is configured. */
    public function user(): ?Authenticatable
    {
        return $this->auth?->user();
    }

    public function registry(): PermissionRegistry
    {
        return $this->registry;
    }
}
