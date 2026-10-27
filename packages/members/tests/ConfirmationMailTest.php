<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DynamicRecord;
use Preflow\Members\ConfirmationMail;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

/** The confirmation link mail and Members::resendConfirmation() for staff. */
final class ConfirmationMailTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_send_revokes_the_earlier_link_and_mails_a_fresh_one(): void
    {
        $m = $this->unconfirmed();
        $old = $this->tokens()->issue(MembersConfig::CONFIRM_PURPOSE, (string) $m->getId(), 3600);

        $this->container()->get(ConfirmationMail::class)->send($m);

        $this->assertNull($this->tokens()->consume($old, MembersConfig::CONFIRM_PURPOSE), 'the earlier link is revoked');
        $mail = $this->onlyMail();
        $this->assertSame(['ada@example.com'], $mail->to);
        $this->assertSame((string) $m->getId(), $this->tokens()->consume($this->linkToken($mail->text), MembersConfig::CONFIRM_PURPOSE));
    }

    public function test_send_later_queues_the_mail_after_revoking(): void
    {
        $m = $this->unconfirmed();
        $old = $this->tokens()->issue(MembersConfig::CONFIRM_PURPOSE, (string) $m->getId(), 3600);

        $this->container()->get(ConfirmationMail::class)->sendLater($m);
        $this->assertSame([], $this->mails(), 'queued');
        $this->container()->get(Notifier::class)->flushDeferred();

        $this->assertNull($this->tokens()->consume($old, MembersConfig::CONFIRM_PURPOSE));
        $this->assertSame((string) $m->getId(), $this->tokens()->consume($this->linkToken($this->onlyMail()->text), MembersConfig::CONFIRM_PURPOSE));
    }

    public function test_resend_confirmation_uses_the_corrected_address(): void
    {
        $members = $this->container()->get(Members::class);
        $m = $this->unconfirmed();
        $m->set('email', 'ada@corrected.example');
        $this->container()->get(\Preflow\Data\DataManager::class)->saveType($m, false);

        $members->resendConfirmation($members->find((string) $m->getId()) ?? $this->fail('member gone'));

        $this->assertSame(['ada@corrected.example'], $this->onlyMail()->to);
    }

    public function test_resend_confirmation_refuses_a_confirmed_member(): void
    {
        $members = $this->container()->get(Members::class);
        $m = $this->unconfirmed();
        $members->confirm($m);

        try {
            $members->resendConfirmation($m);
            $this->fail('expected a LogicException');
        } catch (\LogicException) {
            $this->assertSame([], $this->mails());
        }
    }

    public function test_resend_confirmation_without_the_service_throws(): void
    {
        $c = $this->container();
        $bare = new Members($c->get(\Preflow\Data\DataManager::class), $c->get(\Preflow\Auth\PasswordHasherInterface::class));
        $m = $this->unconfirmed();

        $this->expectException(\LogicException::class);
        $bare->resendConfirmation($m);
    }

    public function test_a_transport_failure_propagates_and_the_member_can_be_resent_afterwards(): void
    {
        $c = $this->container();
        $m = $this->unconfirmed();
        $old = $this->tokens()->issue(MembersConfig::CONFIRM_PURPOSE, (string) $m->getId(), 3600);
        $config = $c->get(MembersConfig::class);
        $failing = new ConfirmationMail($this->tokens(), $this->mailerWith(new Notifier($c->get(TransportFactory::class), [])), $config);

        try {
            $failing->send($m);
            $this->fail('expected a TransportException');
        } catch (TransportException) {
        }
        $this->assertSame([], $this->mails());
        $this->assertNull($this->tokens()->consume($old, MembersConfig::CONFIRM_PURPOSE), 'the earlier link is revoked');

        // The mail channel works again: another send mails, and its link consumes.
        (new ConfirmationMail($this->tokens(), $this->mailerWith($c->get(Notifier::class)), $config))->send($m);
        $this->assertSame((string) $m->getId(), $this->tokens()->consume($this->linkToken($this->onlyMail()->text), MembersConfig::CONFIRM_PURPOSE));
    }

    public function test_resend_confirmation_propagates_a_transport_failure_and_a_second_one_works(): void
    {
        $c = $this->container();
        $config = $c->get(MembersConfig::class);
        $mode = ['fail' => true];
        $failing = new ConfirmationMail($this->tokens(), $this->mailerWith(new Notifier($c->get(TransportFactory::class), [])), $config);
        $working = new ConfirmationMail($this->tokens(), $this->mailerWith($c->get(Notifier::class)), $config);
        $members = new Members(
            $c->get(\Preflow\Data\DataManager::class),
            $c->get(\Preflow\Auth\PasswordHasherInterface::class),
            confirmationMail: static function () use (&$mode, $failing, $working): ConfirmationMail {
                return $mode['fail'] ? $failing : $working;
            },
        );
        $m = $this->unconfirmed();

        try {
            $members->resendConfirmation($m);
            $this->fail('expected a TransportException');
        } catch (TransportException) {
        }
        $this->assertSame([], $this->mails());

        $mode['fail'] = false;
        $members->resendConfirmation($m);
        $this->assertSame((string) $m->getId(), $this->tokens()->consume($this->linkToken($this->onlyMail()->text), MembersConfig::CONFIRM_PURPOSE));
    }

    // --- helpers -------------------------------------------------------------------------------

    private function mailerWith(Notifier $n): \Preflow\Members\MemberMailer
    {
        $c = $this->container();

        return new \Preflow\Members\MemberMailer(
            $n,
            $c->get(\Preflow\View\TemplateEngineInterface::class),
            $c->get(\Preflow\I18n\Translator::class),
            $c->get(\Preflow\Members\Links::class),
            $c->get(\Preflow\Data\Locales::class),
            $c->get(MembersConfig::class),
            $c->get(\Preflow\Members\Admission\Admission::class),
            $c->get(\Preflow\Members\LinkValidity::class),
        );
    }

    private function unconfirmed(): DynamicRecord
    {
        return $this->container()->get(Members::class)->register('ada@example.com', 'Ada', 'correct horse battery', 'en');
    }

    private function tokens(): Tokens
    {
        return $this->container()->get(Tokens::class);
    }

    private function linkToken(string $text): string
    {
        $this->assertSame(1, preg_match('~/confirm/([^\s]+)~', $text, $mm));

        return $mm[1];
    }

    /** @return list<\Preflow\Notify\Email> */
    private function mails(): array
    {
        $sent = $this->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }

    private function onlyMail(): \Preflow\Notify\Email
    {
        $mails = $this->mails();
        $this->assertCount(1, $mails);

        return $mails[0];
    }
}
