<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Access\Access;
use Preflow\Auth\AuthManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Users\RecordUser;

/**
 * Who is looking at the member pages: a signed-in member (guard "members"), Folio staff
 * (the default guard) or nobody.
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

    public function isStaff(): bool
    {
        return array_key_exists($this->auth->defaultGuardName(), $this->auth->users());
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
        $staff = $this->auth->users()[$this->auth->defaultGuardName()] ?? null;

        return $staff !== null && method_exists($staff, 'getEmail') ? (string) $staff->getEmail() : '';
    }

    public function language(string $fallback): string
    {
        $language = (string) ($this->member()?->get('language') ?? '');

        return $language !== '' ? $language : $fallback;
    }
}
