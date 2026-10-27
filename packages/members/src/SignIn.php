<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\AuthManager;
use Preflow\Auth\Record\RecordUser;
use Preflow\Data\DynamicRecord;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signs a member in as the member pages do (the members guard, with the remember cookie), so a
 * project can sign in an account it has just created (Members::register(…, emailVerified: true)).
 * Only an admitted member: the member pages sign in no one else either. The member pages sign in
 * through it too; it raises MemberEvents::SIGNED_IN.
 */
final class SignIn
{
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ?MemberEvents $events = null,
    ) {}

    /** @throws \LogicException when $member is not admitted (unconfirmed, waiting for the owner, declined) */
    public function in(DynamicRecord $member, ServerRequestInterface $request): void
    {
        if (MemberState::fromRecord($member) !== MemberState::Member) {
            throw new \LogicException('SignIn::in(): only an admitted member signs in; this one is ' . MemberState::fromRecord($member)->value . '.');
        }
        $this->auth->guard(MembersConfig::GUARD)->login(new RecordUser($member), $request, remember: true);
        $this->events?->dispatch(MemberEvents::SIGNED_IN, ['member' => $member, 'request' => $request]);
    }
}
