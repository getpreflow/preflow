<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Data\DynamicRecord;
use Preflow\Auth\Record\RecordUser;

/**
 * Who is looking at the member pages: a signed-in member (guard "members"), staff (signed in
 * with the default guard, as Folio's admins are, when that guard's population is staff) or nobody.
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
     * Signed in with the default guard, and that guard signs in staff: Folio marks it so, or
     * config/auth.php gives it 'population' => 'staff'. A default guard of another population
     * (the starter's employers) is no staff here.
     */
    public function isStaff(): bool
    {
        $guard = $this->auth->defaultGuardName();

        return array_key_exists($guard, $this->auth->users()) && $this->access->populationOf($guard) === 'staff';
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
        $staff = $this->isStaff() ? ($this->auth->users()[$this->auth->defaultGuardName()] ?? null) : null;

        return $staff !== null && method_exists($staff, 'getEmail') ? (string) $staff->getEmail() : '';
    }

    public function language(string $fallback): string
    {
        $language = (string) ($this->member()?->get('language') ?? '');

        return $language !== '' ? $language : $fallback;
    }
}
