<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Data\DynamicRecord;
use Preflow\Members\Admission\Admission;
use Preflow\Members\Admission\Decision;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What follows a proven address, whoever proved it (the confirm link, or a project with
 * Members::register(…, emailVerified: true)): the member is confirmed, the configured Admission
 * decides, and the member becomes "member" or "pending" (then the owner hears of it).
 * Signing in and where to go next are the caller's.
 */
final class Confirmation
{
    public function __construct(
        private readonly Members $members,
        private readonly Admission $admission,
        private readonly MemberMailer $mailer,
        private readonly MembersSettings $settings,
    ) {}

    public function confirm(DynamicRecord $member, ServerRequestInterface $request): Decision
    {
        $this->members->confirm($member);
        $decision = $this->admission->afterConfirm($member, $request);
        if ($decision === Decision::Accept) {
            $this->members->decide($member, 'member');

            return $decision;
        }
        $this->members->decide($member, 'pending');
        $this->tellOwner($member);

        return $decision;
    }

    private function tellOwner(DynamicRecord $member): void
    {
        $to = $this->settings->noticeEmail();
        if ($to === null) {
            return;
        }
        $id = (string) $member->getId();
        $this->mailer->noticeLater($member, $to, static function () use ($id): void {
            error_log('members: owner notice failed for member ' . $id);
        });
    }
}
