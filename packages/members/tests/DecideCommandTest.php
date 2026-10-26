<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Members\Console\DecideCommand;
use Preflow\Members\Members;
use Preflow\Members\MemberState;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\TransportFactory;

/** members:decide <email> accept|decline: the owner's decision for a site with OwnerApproval and no Folio. */
final class DecideCommandTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold(locales: ['en', 'de']);
        $this->write('config/members.php', "<?php\nreturn ['admission' => " . var_export(\Preflow\Members\Admission\OwnerApproval::class, true) . "];\n");
        $this->migrate();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_accept_admits_a_waiting_member_and_sends_the_accepted_mail(): void
    {
        $this->waiting('ada@example.test', 'de');

        [$code, $out] = $this->decide('ada@example.test', 'accept');

        $this->assertSame(0, $code, $out);
        $this->assertSame(MemberState::Member, MemberState::fromRecord($this->find('ada@example.test')));
        $mails = $this->mails();
        $this->assertCount(1, $mails);
        $this->assertSame(['ada@example.test'], $mails[0]->to);
        $this->assertSame('Ihr Konto ist bereit', $mails[0]->subject);
        $this->assertStringContainsString('https://site.test/de/members/login', $mails[0]->text, 'the default message, filled in');
        $this->assertStringContainsString('Ada Lovelace', $mails[0]->text);
        $this->assertStringNotContainsString('{', $mails[0]->text);
    }

    public function test_decline_rejects_and_sends_the_declined_mail(): void
    {
        $this->waiting('ada@example.test', 'en');

        [$code] = $this->decide('ada@example.test', 'decline');

        $this->assertSame(0, $code);
        $this->assertSame(MemberState::Declined, MemberState::fromRecord($this->find('ada@example.test')));
        $this->assertSame('About your registration', $this->mails()[0]->subject);
    }

    public function test_an_unknown_email_exits_1(): void
    {
        [$code, $out] = $this->decide('nobody@example.test', 'accept');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No member', $out);
        $this->assertSame([], $this->mails());
    }

    public function test_a_member_who_is_not_waiting_exits_1_and_changes_nothing(): void
    {
        $m = $this->container()->get(Members::class)->register('ada@example.test', 'Ada Lovelace', 'correct horse battery', 'en', []);
        [$code, $out] = $this->decide('ada@example.test', 'accept');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('has not confirmed', $out);

        $this->container()->get(Members::class)->confirm($m);
        $this->container()->get(Members::class)->decide($m, 'member');
        [$code, $out] = $this->decide('ada@example.test', 'decline');
        $this->assertSame(1, $code);
        $this->assertStringContainsString('is not waiting', $out);
        $this->assertSame(MemberState::Member, MemberState::fromRecord($this->find('ada@example.test')));
        $this->assertSame([], $this->mails());
    }

    public function test_bad_arguments_exit_1(): void
    {
        $this->waiting('ada@example.test', 'en');
        foreach ([[], ['ada@example.test'], ['ada@example.test', 'maybe']] as $args) {
            [$code, $out] = $this->command(new DecideCommand(fn () => $this->app()), $args);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('Usage: members:decide', $out);
        }
        $this->assertSame(MemberState::Waiting, MemberState::fromRecord($this->find('ada@example.test')));
    }

    public function test_an_address_is_found_whatever_its_case(): void
    {
        $this->waiting('ada@example.test', 'en');

        [$code] = $this->decide(' Ada@Example.test ', 'accept');

        $this->assertSame(0, $code);
    }

    /** @return array{0: int, 1: string} */
    private function decide(string $email, string $what): array
    {
        $this->flow = $this->app(); // a fresh app, like the CLI's own; its in-memory mail transport is read afterwards

        return $this->command(new DecideCommand(fn () => $this->flow), [$email, $what]);
    }

    private function waiting(string $email, string $lang): void
    {
        $members = $this->container()->get(Members::class);
        $m = $members->register($email, 'Ada Lovelace', 'correct horse battery', $lang, []);
        $members->confirm($m);
        $members->decide($m, 'pending');
    }

    private function find(string $email): \Preflow\Data\DynamicRecord
    {
        return $this->container()->get(Members::class)->findByEmail($email) ?? $this->fail('No member ' . $email);
    }

    /** @return list<\Preflow\Notify\Email> */
    private function mails(): array
    {
        return array_values(array_map(static fn ($s) => $s->message, $this->container()->get(TransportFactory::class)->get('mail')->sent()));
    }
}
