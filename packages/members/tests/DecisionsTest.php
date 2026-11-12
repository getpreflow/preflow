<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\DynamicRecord;
use Preflow\Members\DecisionRefused;
use Preflow\Members\MemberMailer;
use Preflow\Members\MemberRoles;
use Preflow\Members\Members;
use Preflow\Members\MembersConfig;
use Preflow\Members\MemberState;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

/** Members::accept() and decline(): the owner's decision, the same rules from Folio and the CLI. */
final class DecisionsTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->write('config/members.php', "<?php\nreturn ['admission' => " . var_export(\Preflow\Members\Admission\OwnerApproval::class, true) . "];\n");
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_accept_from_waiting_and_declined(): void
    {
        foreach (['pending', 'rejected'] as $i => $role) {
            $m = $this->member("a{$i}@example.test", $role);
            $this->members()->accept($m, 'Welcome.');
            $this->assertSame(MemberState::Member, MemberState::fromRecord($m), $role);
            $this->assertNotSame('', (string) $m->get('decided_at'));
        }
        $this->assertCount(2, $this->mails());
    }

    public function test_decline_from_waiting_and_member(): void
    {
        foreach (['pending', 'member'] as $i => $role) {
            $m = $this->member("d{$i}@example.test", $role);
            $this->members()->decline($m, 'Sorry.');
            $this->assertSame(MemberState::Declined, MemberState::fromRecord($m), $role);
        }
        $this->assertCount(2, $this->mails());
    }

    public function test_accept_member_is_refused(): void
    {
        $m = $this->member('m@example.test', 'member');

        $this->assertRefused('not_allowed', fn () => $this->members()->accept($m, 'Hi'));
        $this->assertSame([], $this->mails());
        $this->assertSame(['member'], MemberRoles::of($m));
    }

    public function test_decline_declined_is_refused(): void
    {
        $m = $this->member('r@example.test', 'rejected');

        $this->assertRefused('not_allowed', fn () => $this->members()->decline($m, 'Hi'));
    }

    public function test_unconfirmed_is_refused(): void
    {
        $m = $this->members()->register('u@example.test', 'Una', 'correct horse battery', 'en', []);

        $this->assertRefused('unconfirmed', fn () => $this->members()->accept($m, 'Hi'));
        $this->assertRefused('unconfirmed', fn () => $this->members()->decline($m, 'Hi'));
        $this->assertSame([], $this->mails());
    }

    public function test_empty_message_is_refused(): void
    {
        $m = $this->member('e@example.test', 'pending');

        $this->assertRefused('empty_message', fn () => $this->members()->accept($m, "  \n "));
        $this->assertRefused('empty_message', fn () => $this->members()->decline($m, ''));
        $this->assertSame(MemberState::Waiting, MemberState::fromRecord($m));
        $this->assertSame([], $this->mails());
    }

    public function test_decline_revokes_remember_me(): void
    {
        $m = $this->member('rm@example.test', 'member');
        $remember = $this->container()->get(RememberMe::class);
        $cookie = $remember->issue(MembersConfig::GUARD, (string) $m->getId());
        $this->assertNotNull($remember->resolve(MembersConfig::GUARD, $cookie));

        $this->members()->decline($m, 'Sorry.');

        $this->assertNull($remember->resolve(MembersConfig::GUARD, $cookie));
    }

    public function test_failed_mail_changes_nothing(): void
    {
        $c = $this->container();
        $m = $this->member('f@example.test', 'pending');
        $failing = new MemberMailer(
            new \Preflow\Notify\Mail\Mailer(new Notifier($c->get(TransportFactory::class), []), $c->get(\Preflow\Notify\Mail\MailLayout::class), $c->get(\Preflow\Notify\Mail\MailConfig::class)),
            $c->get(\Preflow\I18n\Translator::class),
            $c->get(\Preflow\Members\Links::class),
            $c->get(\Preflow\Data\Locales::class),
            $c->get(MembersConfig::class),
            $c->get(\Preflow\Members\Admission\Admission::class),
            $c->get(\Preflow\Members\LinkValidity::class),
        );
        $members = new Members($c->get(\Preflow\Data\DataManager::class), $c->get(\Preflow\Auth\PasswordHasherInterface::class), mailer: static fn (): MemberMailer => $failing);

        try {
            $members->accept($m, 'Welcome.');
            $this->fail('expected a TransportException');
        } catch (TransportException) {
        }

        $this->assertSame(['pending'], MemberRoles::of($m));
        $stored = $this->members()->find((string) $m->getId());
        $this->assertSame(['pending'], MemberRoles::of($stored));
        $this->assertSame([], $this->mails());
    }

    private function assertRefused(string $reason, \Closure $call): void
    {
        try {
            $call();
            $this->fail('expected a DecisionRefused');
        } catch (DecisionRefused $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function members(): Members
    {
        return $this->container()->get(Members::class);
    }

    private function member(string $email, string $role): DynamicRecord
    {
        $m = $this->members()->register($email, 'Ada Lovelace', 'correct horse battery', 'en', []);
        $this->members()->confirm($m);
        $this->members()->decide($m, $role);

        return $m;
    }

    /** @return list<\Preflow\Notify\Email> */
    private function mails(): array
    {
        return array_values(array_map(static fn ($s) => $s->message, $this->container()->get(TransportFactory::class)->get('mail')->sent()));
    }
}
