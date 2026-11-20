<?php

declare(strict_types=1);

namespace Preflow\Auth\Access;

use Preflow\Auth\Authenticatable;
use Preflow\Auth\AuthManager;
use Preflow\Auth\SyntheticUser;
use Preflow\Core\Context\RoleView;

/**
 * The one permission question: can('page.publish'). It asks about the user
 * signed in for this request, in its population (staff or members, per guard),
 * and lets the installed resolver answer. Undeclared permissions throw in
 * debug and are denied (logged once) in production; so are
 * those of a disabled extension.
 *
 * A role view (view as role: the user is a SyntheticUser) has no account and no guard: it is
 * answered from what its roles grant in its own population (populationOfRoleView()), through
 * the resolver's GrantsForRoles (since 0.81.0). No account is read and the resolver's allows()
 * is never asked for it, so a role view never gets more than its roles' own grants; a resolver
 * without GrantsForRoles grants it nothing.
 */
final class Access
{
    private PermissionResolver $resolver;

    /** @var array<string, true> undeclared permissions already logged this request */
    private array $logged = [];

    /** @var array<string, true> guards marked as staff by a package (Folio) */
    private array $staffGuards = [];

    /**
     * @param array<string, string> $populations guard name => population ('staff', 'members' or the app's own name) from config/auth.php
     */
    public function __construct(
        private readonly ?AuthManager $auth,
        private readonly PermissionRegistry $registry,
        private readonly bool $debug,
        private readonly array $populations,
    ) {
        $this->resolver = new DefaultResolver();
    }

    /**
     * The names of the roles defined for a population, when the resolver lists them (ListsRoles:
     * preflow/roles, preflow/rbac); null when it has no roles to list (the default resolver).
     *
     * @return list<string>|null
     */
    public function roles(string $population): ?array
    {
        return $this->resolver instanceof ListsRoles ? $this->resolver->roles($population) : null;
    }

    public function setResolver(PermissionResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** The resolver answering now: DefaultResolver until a package sets one (a package may wrap it, or leave another's in place). */
    public function resolver(): PermissionResolver
    {
        return $this->resolver;
    }

    /** A package that authenticates staff with this guard (Folio) marks it; config still wins. */
    public function markStaffGuard(string $guard): void
    {
        $this->staffGuards[$guard] = true;
    }

    /**
     * A guard declared 'staff' is staff; one declared any other population ('members', or a name of
     * the app's own, such as the starter's 'employers') counts as members, and a package's mark never
     * makes it staff. A guard without a population is staff when a package marked it, else members.
     *
     * @return 'staff'|'members'
     */
    public function populationOf(string $guard): string
    {
        $configured = $this->declaredPopulation($guard);
        if ($configured !== null) {
            return $configured === 'staff' ? 'staff' : 'members';
        }

        return isset($this->staffGuards[$guard]) ? 'staff' : 'members';
    }

    /** The population config/auth.php declares for the guard (guards.<name>.population), as written; null when none. */
    public function declaredPopulation(string $guard): ?string
    {
        $configured = $this->populations[$guard] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * The population a role view (view as role) counts in: 'staff' for a RoleView of the staff
     * population, 'members' for any other ('member', 'members'), as populationOf() counts a guard.
     *
     * @since 0.81.0
     *
     * @return 'staff'|'members'
     */
    public static function populationOfRoleView(RoleView $view): string
    {
        return $view->population === 'staff' ? 'staff' : 'members';
    }

    /**
     * The population ('staff'|'members') of the user signed in for this request; null when nobody is, or no auth is configured.
     * A role view's is its RoleView's (populationOfRoleView()), never a guard's.
     */
    public function population(): ?string
    {
        $user = $this->auth?->user();
        if ($user instanceof SyntheticUser) {
            return self::populationOfRoleView($user->view);
        }
        $guard = $this->auth?->guardName();

        return $user === null || $guard === null ? null : $this->populationOf($guard);
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
        if ($user instanceof SyntheticUser) {
            return $this->grants($user->view, $permission);
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
            if ($user instanceof SyntheticUser ? $this->grants($user->view, $permission) : $this->resolver->allows($user, $this->populationOf((string) $guard), $permission, $subject)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $view's roles grant $permission (GrantsForRoles); false when the resolver cannot say. */
    private function grants(RoleView $view, string $permission): bool
    {
        if (!$this->resolver instanceof GrantsForRoles) {
            return false;
        }
        foreach ($this->resolver->grantsFor($view->roles, self::populationOfRoleView($view)) as $pattern) {
            if (Pattern::matches($pattern, $permission)) {
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
