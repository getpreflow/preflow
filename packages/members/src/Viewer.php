<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Auth\SyntheticUser;
use Preflow\Data\DynamicRecord;
use Preflow\Auth\Record\RecordUser;

/**
 * Who is looking at the member pages: a signed-in member (guard "members"), staff (signed in
 * with a guard of the staff population, as Folio's admins are) or nobody. While the site is
 * viewed as a role (view as role, since 0.81.0), the role view's population decides: a member
 * role is a SyntheticMember (empty profile, no account), a staff role is staff without a name,
 * and no account is read.
 */
final class Viewer
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Access $access,
        private readonly Members $members,
    ) {}

    /** The signed-in member's record; a SyntheticMember under a member role view (since 0.81.0); null for nobody and for staff. */
    public function member(): DynamicRecord|SyntheticMember|null
    {
        $role = $this->roleView();
        if ($role !== null) {
            return Access::populationOfRoleView($role->view) === 'members' ? new SyntheticMember($role->view) : null;
        }
        $user = $this->auth->users()[MembersConfig::GUARD] ?? null;
        if (!$user instanceof RecordUser) {
            return null;
        }

        return $this->members->find($user->getAuthId()) ?? $user->record();
    }

    /**
     * Signed in with a guard that signs in staff: Folio marks its staff guard so (config/folio.php
     * 'guard', else the default guard), or config/auth.php gives it 'population' => 'staff'. A guard
     * of another population (the starter's employers, the members) is no staff here.
     */
    public function isStaff(): bool
    {
        return $this->staff() !== null;
    }

    /** The staff user signed in for this request (the first staff guard's; a staff role view's SyntheticUser), or null. */
    private function staff(): ?object
    {
        $role = $this->roleView();
        if ($role !== null) {
            return Access::populationOfRoleView($role->view) === 'staff' ? $role : null;
        }
        foreach ($this->auth->users() as $guard => $user) {
            if ($this->access->populationOf((string) $guard) === 'staff') {
                return $user;
            }
        }

        return null;
    }

    public function canAccess(): bool
    {
        return $this->access->canAny(MembersConfig::PERMISSION);
    }

    public function name(): string
    {
        $member = $this->member();
        if ($member !== null) {
            return (string) $member->get('name');
        }
        $staff = $this->staff();

        return $staff !== null && method_exists($staff, 'getEmail') ? (string) $staff->getEmail() : '';
    }

    /** The role view's user while the site is viewed as a role (view as role), else null. */
    private function roleView(): ?SyntheticUser
    {
        $user = $this->auth->user();

        return $user instanceof SyntheticUser ? $user : null;
    }

    public function language(string $fallback): string
    {
        $language = (string) ($this->member()?->get('language') ?? '');

        return $language !== '' ? $language : $fallback;
    }
}
