<?php

declare(strict_types=1);

namespace Preflow\Members;

use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DynamicRecord;
use Preflow\Notify\TransportException;

/**
 * The confirmation link of an unconfirmed member: revokes the earlier ones, issues a fresh one and mails it
 * to the stored address. One definition for the public resend, a repeated registration and staff resending.
 */
final class ConfirmationMail
{
    public function __construct(
        private readonly Tokens $tokens,
        private readonly MemberMailer $mailer,
        private readonly MembersConfig $config,
    ) {}

    /**
     * Sends at once. The earlier link is revoked before the mail goes, so after a failure the member
     * has no working link until the next send.
     *
     * @throws TransportException
     */
    public function send(DynamicRecord $m): void
    {
        $this->mailer->confirm($m, $this->fresh($m));
    }

    /**
     * Queues the mail (it goes out after the response).
     *
     * @param ?\Closure(\Throwable): void $onFailure
     */
    public function sendLater(DynamicRecord $m, ?\Closure $onFailure = null): void
    {
        $this->mailer->confirmLater($m, $this->fresh($m), $onFailure);
    }

    private function fresh(DynamicRecord $m): string
    {
        $id = (string) $m->getId();
        $this->tokens->revokeAll(MembersConfig::CONFIRM_PURPOSE, $id);

        return $this->tokens->issue(MembersConfig::CONFIRM_PURPOSE, $id, $this->config->confirmTtl());
    }
}
