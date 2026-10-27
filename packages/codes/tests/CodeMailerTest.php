<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Preflow\Codes\CodeMailer;
use Preflow\Codes\CodeRecord;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;

/** The invitation mail: copy in the recipient's language, an absolute personal link, escaped free text, no header injection. */
final class CodeMailerTest extends CodesAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'cookie' => false];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'Site <hello@site.test>']]];\n");
        $this->write('app/pages/index.twig', 'HOME');
    }

    public function test_sends_link_message_and_action_in_recipient_language(): void
    {
        $this->assertTrue($this->mailer()->send($this->code(), 'ada@example.com', '/invite/x', [
            'language' => 'de', 'from_name' => 'Ben', 'message' => "Bring Spiele mit.\nBis bald!",
        ]));

        $mail = $this->onlyMail();
        $this->assertSame(['ada@example.com'], $mail->to);
        $this->assertSame('Sie sind eingeladen', $mail->subject);
        $this->assertSame('Site <hello@site.test>', $mail->from);
        $this->assertSame(
            "Ben lädt Sie ein.\n\nBring Spiele mit.\nBis bald!\n\nZur Einladung: https://site.test/invite/x\n\nDer Link ist persönlich und gültig bis 02.11.2026.",
            $mail->text,
        );
        $html = (string) $mail->html;
        $this->assertStringContainsString('Einladung öffnen', $html);
        $this->assertStringContainsString('href="https://site.test/invite/x"', $html);
        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('Bring Spiele mit.<br>' . "\n" . 'Bis bald!', $html);
    }

    public function test_anonymous_and_open_ended_codes_drop_their_lines(): void
    {
        $this->mailer()->send($this->code(expires: false), 'ada@example.com', 'https://other.test/i/1', ['language' => 'fr']);

        $this->assertSame(
            "Vous êtes invité·e.\n\nVotre invitation : https://other.test/i/1\n\nLe lien est personnel.",
            $this->onlyMail()->text,
        );
    }

    public function test_unknown_language_falls_back_to_the_site_default(): void
    {
        $this->mailer()->send($this->code(), 'ada@example.com', '/i/1', ['language' => 'xx']);

        $this->assertSame("You're invited", $this->onlyMail()->subject);
        $this->assertStringContainsString('valid until Nov 2, 2026.', $this->onlyMail()->text);
    }

    public function test_message_is_escaped_and_never_in_headers(): void
    {
        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x', [
            'message' => "<script>x</script>\r\nBcc: a@b.c",
            'from_name' => "Ben\r\nBcc: e@f.g",
            'subject' => "Hi\r\nBcc: x@y.z",
        ]);

        $mail = $this->onlyMail();
        $this->assertSame('Hi Bcc: x@y.z', $mail->subject);
        $this->assertSame([], $mail->bcc);
        $this->assertStringContainsString('&lt;script&gt;', (string) $mail->html);
        $this->assertStringNotContainsString('<script>', (string) $mail->html);
        $this->assertStringNotContainsString("\r", $mail->text);
        $this->assertStringContainsString("<script>x</script>\nBcc: a@b.c", $mail->text);
        $this->assertStringContainsString('Ben Bcc: e@f.g invites you.', $mail->text);
    }

    public function test_relative_url_made_absolute_and_bad_url_refused(): void
    {
        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x');
        $this->assertStringContainsString('https://site.test/invite/x', $this->onlyMail()->text);

        foreach (['javascript:x', '//evil.test/x', 'invite/x', "/a\r\nb", 'ftp://site.test/x', ''] as $bad) {
            try {
                $this->mailer()->send($this->code(), 'ada@example.com', $bad);
                $this->fail('refused: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(1, $this->mails());
    }

    public function test_transport_failure_returns_false_and_code_stays_open(): void
    {
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'nope']], 'channels' => ['email' => ['transport' => 'mail']]];\n");
        $log = $this->dir . '/error.log';
        $before = ini_set('error_log', $log);
        $code = $this->code();

        try {
            $this->assertFalse($this->mailer()->send($code, 'ada@example.com', '/invite/x'));
        } finally {
            ini_set('error_log', (string) $before);
        }

        $this->assertTrue($code->isOpen($this->now));
        $this->assertStringContainsString('codes:', (string) file_get_contents($log));
        $this->assertStringNotContainsString('ada@example.com', (string) file_get_contents($log));
    }

    public function test_app_template_and_vars_used(): void
    {
        $this->write('resources/mail/site.twig', '<b>{{ site }}|{{ subject }}|{{ action.url }}|{{ message }}|{{ from_name }}|{{ lang }}</b>');

        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x', [
            'template' => 'resources/mail/site.twig', 'vars' => ['site' => 'BG Genius'], 'message' => 'Hi', 'from_name' => 'Ben',
        ]);

        $this->assertSame('<b>BG Genius|You&#039;re invited|https://site.test/invite/x|Hi|Ben|en</b>', $this->onlyMail()->html);
    }

    public function test_config_layout_and_sender(): void
    {
        $this->write('config/codes.php', "<?php\nreturn ['purposes' => [], 'mail' => ['layout' => 'resources/mail/site.twig', 'from' => 'Club <club@site.test>']];\n");
        $this->write('resources/mail/site.twig', 'LAYOUT');

        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x');

        $this->assertSame('LAYOUT', $this->onlyMail()->html);
        $this->assertSame('Club <club@site.test>', $this->onlyMail()->from);
    }

    public function test_no_brand_colour_unless_configured(): void
    {
        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x');
        $html = (string) $this->onlyMail()->html;
        $this->assertStringNotContainsString('d42a34', strtolower($html));
        $this->assertStringContainsString('#1a1a1a', $html);
    }

    public function test_configured_accent_is_used(): void
    {
        $this->write('config/codes.php', "<?php\nreturn ['purposes' => [], 'mail' => ['accent' => '#0a7d33']];\n");
        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x');
        $html = (string) $this->onlyMail()->html;
        $this->assertStringContainsString('background:#0a7d33', $html);
        $this->assertStringContainsString('color:#0a7d33', $html);
    }

    public function test_only_the_invitation_link_is_linked_and_message_is_trimmed(): void
    {
        $this->mailer()->send($this->code(), 'ada@example.com', '/invite/x', ['message' => "\nSee https://evil.test/x now\n"]);
        $mail = $this->onlyMail();
        $this->assertStringContainsString('See https://evil.test/x now', (string) $mail->html);
        $this->assertStringNotContainsString('href="https://evil.test', (string) $mail->html);
        $this->assertSame(2, substr_count((string) $mail->html, 'href="https://site.test/invite/x"'));
        $this->assertStringContainsString("You're invited.\n\nSee https://evil.test/x now\n\nOpen your invitation", $mail->text);
    }

    private function mailer(): CodeMailer
    {
        return $this->app()->container()->get(CodeMailer::class);
    }

    private function code(bool $expires = true): CodeRecord
    {
        return new CodeRecord('1', 'p', 'ABC', null, [], $expires ? new \DateTimeImmutable('2026-11-02 12:00:00') : null, 0, 1, null, 'readable');
    }

    /** @return list<Email> */
    private function mails(): array
    {
        $sent = $this->app()->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }

    private function onlyMail(): Email
    {
        $this->assertCount(1, $this->mails());

        return $this->mails()[0];
    }
}
