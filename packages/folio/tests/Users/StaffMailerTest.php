<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Users;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Users\StaffMailer;
use Preflow\I18n\Translator;
use Preflow\Notify\Mail\BasicMailLayout;
use Preflow\Notify\Mail\MailConfig;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;

final class StaffMailerTest extends TestCase
{
    private const LINK = 'https://site.test/folio/password/abc123';

    public function test_invite_mail_has_html_with_the_link_as_button(): void
    {
        $factory = new TransportFactory(['mail' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mail', 'from' => 'hello@site.test']], defer: false);

        $this->mailer($notifier)->sendLater('invite', 'ada@site.test', 'Ada', self::LINK, 'account 7', 'de');

        $sent = $factory->get('mail')->sent();
        $this->assertCount(1, $sent);
        $mail = $sent[0]->message;
        $this->assertSame(['ada@site.test'], $mail->to);
        $this->assertStringContainsString(self::LINK, $mail->text);
        $this->assertStringContainsString('Hallo Ada', $mail->text);
        $this->assertStringContainsString('data-mail-action href="' . self::LINK . '"', (string) $mail->html);
        $this->assertStringContainsString('>Einladung annehmen</a>', (string) $mail->html);
        $this->assertStringContainsString('lang="de"', (string) $mail->html);
    }

    public function test_reset_mail_uses_its_own_action_label_and_the_default_language(): void
    {
        $factory = new TransportFactory(['mail' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mail', 'from' => 'hello@site.test']], defer: false);

        $this->mailer($notifier)->sendLater('reset', 'ada@site.test', "Ada\r\nBcc: x@evil.test", self::LINK, 'account 7');

        $mail = $factory->get('mail')->sent()[0]->message;
        $this->assertStringContainsString('>Set your password</a>', (string) $mail->html);
        $this->assertStringNotContainsString("\r", $mail->subject);
        $this->assertStringContainsString('Ada Bcc: x@evil.test', $mail->text);
    }

    public function test_mails_never_mention_folio(): void
    {
        $factory = new TransportFactory(['mail' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mail', 'from' => 'hello@site.test']], defer: false);

        foreach (['en', 'de', 'fr'] as $locale) {
            foreach (['invite', 'reset'] as $kind) {
                $this->mailer($notifier)->sendLater($kind, 'ada@site.test', 'Ada', self::LINK, 'account 7', $locale);
            }
        }

        $sent = $factory->get('mail')->sent();
        $this->assertCount(6, $sent);
        foreach ($sent as $s) {
            $m = $s->message;
            $this->assertStringNotContainsStringIgnoringCase('folio', $m->subject);
            $this->assertStringNotContainsStringIgnoringCase('folio', str_replace(self::LINK, '', $m->text));
            $this->assertStringNotContainsStringIgnoringCase('folio', strip_tags(str_replace(self::LINK, '', (string) $m->html)));
        }
    }

    public function test_the_site_is_named_by_app_name_else_the_host_of_app_url(): void
    {
        $this->assertSame('Site', StaffMailer::siteName('Site', 'https://site.test'));
        $this->assertSame('site.test', StaffMailer::siteName(null, 'https://site.test/'));
        $this->assertSame('site.test', StaffMailer::siteName('  ', ' https://site.test '));
        $this->assertNull(StaffMailer::siteName(null, null));
        $this->assertNull(StaffMailer::siteName('', 'not a url'));
    }

    public function test_without_a_site_name_the_mail_uses_the_texts_without_a_site_in_its_language(): void
    {
        $factory = new TransportFactory(['mail' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'mail', 'from' => 'hello@site.test']], defer: false);

        $this->mailer($notifier, null)->sendLater('reset', 'ada@site.test', 'Ada', self::LINK, 'account 7');
        $this->mailer($notifier, null)->sendLater('reset', 'ada@site.test', 'Ada', self::LINK, 'account 7', 'de');

        [$en, $de] = array_map(static fn ($s) => $s->message, $factory->get('mail')->sent());
        $this->assertSame('Reset your password for the website', $en->subject);
        $this->assertStringEndsWith('Kind regards', $en->text);
        $this->assertSame('Passwort für die Website zurücksetzen', $de->subject);
        $this->assertStringEndsWith('Freundliche Grüße', $de->text);
        $this->assertStringNotContainsString('{site}', $de->text);
        foreach ([$en, $de] as $mail) {
            $this->assertStringNotContainsStringIgnoringCase('folio', $mail->subject);
            $this->assertStringNotContainsStringIgnoringCase('folio', str_replace(self::LINK, '', $mail->text));
        }
    }

    public function test_failure_logs_no_address(): void
    {
        $factory = new TransportFactory(['mail' => ['driver' => 'memory']], sys_get_temp_dir());
        $notifier = new Notifier($factory, ['email' => ['transport' => 'missing', 'from' => 'hello@site.test']]);
        $log = tempnam(sys_get_temp_dir(), 'staffmailer');
        $previous = ini_set('error_log', $log);

        try {
            $this->mailer($notifier)->sendLater('invite', 'ada@site.test', 'Ada', self::LINK, 'account 7');
            $this->assertSame(0, $notifier->flushDeferred(), 'nothing was sent');
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous);
            @unlink($log);
        }

        $this->assertStringContainsString('folio: invite mail failed for account account 7', $logged);
        $this->assertStringNotContainsString('ada@site.test', $logged);
    }

    private function mailer(Notifier $notifier, ?string $site = 'Site'): StaffMailer
    {
        return new StaffMailer(
            new Mailer($notifier, new BasicMailLayout(), new MailConfig()),
            new Translator(dirname(__DIR__, 2) . '/lang', 'en', 'en'),
            'en',
            $site,
        );
    }
}
