<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Members\Tests\Support\Dom;
use Preflow\Members\Tests\Support\MembersScaffold;

/** The confirm, reset and existing-account mails hand the layout a primary action {label, url}. */
final class MailActionTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_the_confirm_mail_gives_the_layout_the_link_and_the_shipped_layout_shows_a_button(): void
    {
        $this->site("['mail' => ['layout' => '@members/probe.twig']]");
        $this->probeLayout();
        $this->mailer()->confirmLater($this->ada(), 'tok123');
        $this->container()->get(Notifier::class)->flushDeferred();

        $mail = $this->onlyMail();
        $this->assertSame('ACTION[Confirm email address|https://site.test/members/confirm/tok123]', trim(strip_tags((string) $mail->html)));
        $this->assertStringContainsString('https://site.test/members/confirm/tok123', $mail->text, 'the text part keeps the link');
    }

    public function test_the_shipped_layout_renders_a_button_with_the_url_below(): void
    {
        $this->site();
        $this->mailer()->confirmLater($this->ada(), 'tok123');
        $this->container()->get(Notifier::class)->flushDeferred();

        $html = (string) $this->onlyMail()->html;
        $button = Dom::first($html, '//a[@data-members-action][@href="https://site.test/members/confirm/tok123"]');
        $this->assertNotNull($button);
        $this->assertSame('Confirm email address', trim($button->textContent));
        $this->assertStringContainsString('https://site.test/members/confirm/tok123', Dom::text($html, '//p[@data-members-action-url]'), 'the URL as text below the button');
    }

    public function test_reset_and_existing_mails_carry_an_action_in_the_member_language(): void
    {
        $this->site("['mail' => ['layout' => '@members/probe.twig']]");
        $this->probeLayout();
        $m = $this->ada('de');
        $this->mailer()->resetLater($m, 'rt');
        $this->mailer()->existingLater($m, 'rt2');
        $this->container()->get(Notifier::class)->flushDeferred();

        [$reset, $existing] = $this->mails();
        $this->assertSame('ACTION[Neues Passwort festlegen|https://site.test/de/members/reset/rt]', trim(strip_tags((string) $reset->html)));
        $this->assertSame('ACTION[Anmelden|https://site.test/de/members/login]', trim(strip_tags((string) $existing->html)));
    }

    public function test_a_layout_that_ignores_the_action_still_works_and_other_mails_have_none(): void
    {
        $this->site("['mail' => ['layout' => '@members/probe.twig']]");
        $this->write('resources/members/probe.twig', '<p>{{ paragraphs|join(" ")|raw }}</p>{% if action is defined and action %}[A]{% endif %}');
        $this->mailer()->confirmLater($this->ada(), 'tok123');
        $this->container()->get(Notifier::class)->flushDeferred();
        $this->mailer()->accepted($this->ada('en', 'bob@example.com'), 'Welcome aboard');

        [$confirm, $accepted] = $this->mails();
        $this->assertStringContainsString('Hi Ada', (string) $confirm->html);
        $this->assertStringEndsWith('[A]', (string) $confirm->html);
        $this->assertSame('<p>Welcome aboard</p>', (string) $accepted->html, 'the decision mails have no action');
    }

    private function probeLayout(): void
    {
        $this->write('resources/members/probe.twig', '{% if action is defined and action %}ACTION[{{ action.label }}|{{ action.url }}]{% else %}NOACTION{% endif %}');
    }

    private function site(string $members = '[]'): void
    {
        $this->flow = null;
        $this->scaffold(locales: ['en', 'de']);
        $this->write('config/members.php', "<?php\nreturn {$members};\n");
        $this->migrate();
    }

    private function ada(string $lang = 'en', string $email = 'ada@example.com'): \Preflow\Data\DynamicRecord
    {
        return $this->container()->get(Members::class)->register($email, 'Ada', 'correct horse battery', $lang, []);
    }

    private function mailer(): MemberMailer
    {
        return $this->container()->get(MemberMailer::class);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_map(static fn ($s) => $s->message, $this->container()->get(TransportFactory::class)->get('mail')->sent()));
    }

    private function onlyMail(): Email
    {
        $mails = $this->mails();
        $this->assertCount(1, $mails);

        return $mails[0];
    }
}
