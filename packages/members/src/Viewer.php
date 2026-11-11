<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Data\DynamicRecord;
use Preflow\Auth\Record\RecordUser;

/**
 * Who is looking at the member pages: a signed-in member (guard "members"), staff (signed in
 * with a guard of the staff population, as Folio's admins are) or nobody.
 */
final class Viewer
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly Access $access,
        private readonly Members $members,
    ) {}

    public function member(): ?DynamicRecord
    {
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

    /** The staff user signed in for this request (the first staff guard's), or null. */
    private function staff(): ?object
    {
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

    public function language(string $fallback): string
    {
        $language = (string) ($this->member()?->get('language') ?? '');

        return $language !== '' ? $language : $fallback;
    }
}
