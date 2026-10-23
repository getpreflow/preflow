<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\I18n\Translator;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Members\MemberState;
use Preflow\Members\Tests\Fixtures\ListedAdmission;
use Preflow\Members\Tests\Support\MembersScaffold;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportException;
use Preflow\Notify\TransportFactory;

/** The member mails: copy from the lang files in the member's language, links from app.url, the HTML layout, the sender. */
final class MemberMailTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function confirmCases(): array
    {
        return [
            'en' => ['en', 'Confirm your email address', 'Hi Ada,', 'https://site.test/members/confirm/tok123', 'The link works for 24 hours.'],
            'de' => ['de', 'Bestätige deine E-Mail-Adresse', 'Hallo Ada,', 'https://site.test/de/members/confirm/tok123', 'Der Link gilt 24 Stunden.'],
            'fr' => ['fr', 'Confirmez votre adresse e-mail', 'Bonjour Ada,', 'https://site.test/fr/members/confirm/tok123', 'Le lien est valable 24 heures.'],
        ];
    }

    #[DataProvider('confirmCases')]
    public function test_confirm_mail_in_the_member_language(string $lang, string $subject, string $greeting, string $link, string $hint): void
    {
        $this->site();
        $m = $this->member('ada@example.com', $lang);

        $this->mailer()->confirmLater($m, 'tok123');
        $this->assertSame([], $this->mails(), 'queued, not sent during the request');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame(['ada@example.com'], $mail->to);
        $this->assertSame($subject, $mail->subject);
        $this->assertStringStartsWith($greeting, $mail->text);
        $this->assertStringContainsString($link, $mail->text);
        $this->assertStringContainsString($hint, $mail->text);
        $this->assertStringContainsString('href="' . $link . '"', (string) $mail->html);
        $this->assertStringContainsString('<html lang="' . $lang . '">', (string) $mail->html);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function existingCases(): array
    {
        return [
            'en' => ['en', 'Your account', 'Log in: https://site.test/members/login', 'Forgot your password? https://site.test/members/reset/rtok'],
            'de' => ['de', 'Dein Konto', 'Anmelden: https://site.test/de/members/login', 'Passwort vergessen? https://site.test/de/members/reset/rtok'],
            'fr' => ['fr', 'Votre compte', 'Se connecter : https://site.test/fr/members/login', 'Mot de passe oublié ? https://site.test/fr/members/reset/rtok'],
        ];
    }

    #[DataProvider('existingCases')]
    public function test_existing_account_mail_links_login_and_reset(string $lang, string $subject, string $login, string $reset): void
    {
        $this->site();
        $m = $this->member('ada@example.com', $lang);

        $this->mailer()->existingLater($m, 'rtok');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame(['ada@example.com'], $mail->to);
        $this->assertSame($subject, $mail->subject);
        $this->assertStringContainsString($login, $mail->text);
        $this->assertStringContainsString($reset, $mail->text);
    }

    /** @return array<string, array{string, string, string}> */
    public static function resetCases(): array
    {
        return [
            'en' => ['en', 'Reset your password', 'https://site.test/members/reset/ptok'],
            'de' => ['de', 'Setze dein Passwort zurück', 'https://site.test/de/members/reset/ptok'],
            'fr' => ['fr', 'Réinitialisez votre mot de passe', 'https://site.test/fr/members/reset/ptok'],
        ];
    }

    #[DataProvider('resetCases')]
    public function test_reset_mail(string $lang, string $subject, string $link): void
    {
        $this->site();
        $m = $this->member('ada@example.com', $lang);

        $this->mailer()->resetLater($m, 'ptok');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame(['ada@example.com'], $mail->to);
        $this->assertSame($subject, $mail->subject);
        $this->assertStringContainsString($link, $mail->text);
    }

    /** @return array<string, array{list<string>, string, string, string}> */
    public static function noticeCases(): array
    {
        return [
            'en' => [['en', 'de', 'fr'], 'Ada Lovelace is waiting for approval', 'Ada Lovelace <ada@example.com> confirmed their email and is waiting for approval.', 'Open in Folio: '],
            'de' => [['de', 'en'], 'Ada Lovelace wartet auf Freigabe', 'Ada Lovelace <ada@example.com> hat die E-Mail-Adresse bestätigt und wartet auf Freigabe.', 'In Folio öffnen: '],
            'fr' => [['fr', 'en'], 'Ada Lovelace attend une validation', 'Ada Lovelace <ada@example.com> a confirmé son adresse e-mail et attend une validation.', 'Ouvrir dans Folio : '],
        ];
    }

    /** @param list<string> $locales */
    #[DataProvider('noticeCases')]
    public function test_notice_goes_to_the_owner_in_the_site_default_locale(array $locales, string $subject, string $lead, string $open): void
    {
        $this->site($locales);
        $m = $this->member('ada@example.com', 'en', 'Ada Lovelace'); // the member's language doesn't matter

        $this->mailer()->noticeLater($m, 'owner@site.test');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame(['owner@site.test'], $mail->to);
        $this->assertSame($subject, $mail->subject);
        $this->assertStringStartsWith($lead, $mail->text);
        $this->assertStringContainsString($open . 'https://site.test/folio/member/' . $m->getId() . '/edit', $mail->text);
        $this->assertStringNotContainsString('{details}', $mail->text);
        $this->assertStringNotContainsString('book code', $mail->text, 'no admission details from OwnerApproval');
    }

    public function test_notice_carries_the_admissions_notice_details_not_the_panel_ones(): void
    {
        $this->site(members: "['admission' => " . ListedAdmission::class . '::class]');
        $m = $this->member('ada@example.com', 'en');

        $this->mailer()->noticeLater($m, 'owner@site.test');
        $this->flush();

        $text = $this->onlyMail()->text;
        $this->assertStringContainsString("waiting for approval.\n\nBook code: given, but it no longer worked.\n\n“I build things”\n\nOpen in Folio:", $text);
        $this->assertStringNotContainsString('book code: no', $text, 'the panel line stays in the panel');
    }

    /** @return array<string, array{string, string, string}> */
    public static function decisionCases(): array
    {
        return [
            'en' => ['en', 'Your account is ready', 'About your registration'],
            'de' => ['de', 'Dein Konto ist bereit', 'Zu deiner Registrierung'],
            'fr' => ['fr', 'Votre compte est prêt', 'À propos de votre inscription'],
        ];
    }

    #[DataProvider('decisionCases')]
    public function test_decision_mails_carry_the_owners_message_at_once(string $lang, string $accepted, string $declined): void
    {
        $this->site();
        $m = $this->member('ada@example.com', $lang);

        $this->mailer()->accepted($m, "Hello Ada,\n\nsee https://site.test/members/login");
        $this->mailer()->declined($m, 'Sorry.');

        $mails = $this->mails(); // sent synchronously, no flush
        $this->assertCount(2, $mails);
        $this->assertSame([$accepted, $declined], [$mails[0]->subject, $mails[1]->subject]);
        $this->assertSame(['ada@example.com'], $mails[0]->to);
        $this->assertSame("Hello Ada,\n\nsee https://site.test/members/login", $mails[0]->text);
        $this->assertStringContainsString('<a href="https://site.test/members/login" style="color:#d42a34">https://site.test/members/login</a>', (string) $mails[0]->html);
        $this->assertSame('Sorry.', $mails[1]->text);
    }

    public function test_a_failed_decision_mail_throws(): void
    {
        $this->site();
        $m = $this->member('ada@example.com', 'en');
        $c = $this->container();
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), []));
        $mailer = $this->mailer(); // resolved after the Notifier was replaced

        $this->expectException(TransportException::class);
        $mailer->declined($m, 'Sorry.');
    }

    public function test_an_unknown_member_language_gets_the_site_default(): void
    {
        $this->site(['de', 'en']);
        $m = $this->member('ada@example.com', 'it');

        $this->mailer()->resetLater($m, 'ptok');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame('Setze dein Passwort zurück', $mail->subject);
        $this->assertStringContainsString('https://site.test/members/reset/ptok', $mail->text);
    }

    public function test_a_site_lang_file_overrides_single_keys(): void
    {
        $this->site();
        $this->write('lang/de/members.php', "<?php\nreturn ['mail' => ['confirm' => ['subject' => 'Bitte bestätigen']]];\n");
        $m = $this->member('ada@example.com', 'de');

        $this->mailer()->confirmLater($m, 'tok');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertSame('Bitte bestätigen', $mail->subject);
        $this->assertStringStartsWith('Hallo Ada,', $mail->text, 'the body is still the package copy');
    }

    public function test_from_name_and_the_channel_address(): void
    {
        $this->site(members: "['mail' => ['from_name' => 'Site Owner']]", from: 'hi@site.test');
        $this->mailer()->resetLater($this->member('ada@example.com', 'en'), 't');
        $this->flush();
        $this->assertSame('Site Owner <hi@site.test>', $this->onlyMail()->from);
        $this->removeScaffold();

        $this->site(members: "['mail' => ['from_name' => 'Site Owner']]", from: 'Old Name <hi@site.test>');
        $this->mailer()->resetLater($this->member('ada@example.com', 'en'), 't');
        $this->flush();
        $this->assertSame('Site Owner <hi@site.test>', $this->onlyMail()->from, 'the name is replaced, the address kept');
        $this->removeScaffold();

        $this->site(from: 'hi@site.test');
        $this->mailer()->resetLater($this->member('ada@example.com', 'en'), 't');
        $this->flush();
        $this->assertSame('hi@site.test', $this->onlyMail()->from, 'without a from name the channel default applies');
    }

    public function test_html_layout_takes_the_accent_and_escapes(): void
    {
        $this->site(members: "['mail' => ['accent' => '#0a7']]");
        $m = $this->member('ada@example.com', 'en', 'Ada <b>bold</b>');

        $this->mailer()->confirmLater($m, 'tok');
        $this->flush();

        $html = (string) $this->onlyMail()->html;
        $this->assertStringContainsString('style="color:#0a7"', $html);
        $this->assertStringContainsString('<title>Confirm your email address</title>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('Ada &lt;b&gt;bold&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Smart Stack', $html, 'the package layout is neutral');
    }

    public function test_a_site_mail_layout_replaces_the_package_layout(): void
    {
        $this->site(members: "['mail' => ['layout' => 'resources/mail/site.twig']]");
        $this->write('resources/mail/site.twig', '<html lang="{{ lang }}"><h1>SITE {{ subject }}</h1>{% for p in paragraphs %}<p>{{ p|raw }}</p>{% endfor %}</html>');
        $m = $this->member('ada@example.com', 'de');

        $this->mailer()->confirmLater($m, 'tok');
        $this->flush();

        $html = (string) $this->onlyMail()->html;
        $this->assertStringContainsString('<h1>SITE Bestätige deine E-Mail-Adresse</h1>', $html);
        $this->assertStringContainsString('href="https://site.test/de/members/confirm/tok"', $html);
    }

    public function test_hostile_names_cannot_break_or_redirect_mail(): void
    {
        $this->site();
        $ada = $this->member('ada@example.com', 'en', "Ada\r\nBcc: x@evil.test");
        $eve = $this->member('eve@example.com', 'en', 'Eve <eve@evil.test><script>x</script>');
        $mailer = $this->mailer();

        $mailer->confirmLater($ada, 'tok');
        $mailer->noticeLater($ada, 'owner@site.test');
        $mailer->confirmLater($eve, 'tok');
        $mailer->noticeLater($eve, 'owner@site.test');
        $this->flush();

        $sent = $this->mails();
        $this->assertCount(4, $sent);
        $expected = [['ada@example.com'], ['owner@site.test'], ['eve@example.com'], ['owner@site.test']];
        foreach ($sent as $i => $email) {
            $this->assertSame($expected[$i], $email->to, "mail $i");
            $this->assertSame([], $email->cc);
            $this->assertSame([], $email->bcc);
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $email->subject);
            $this->assertStringNotContainsString('<script>', (string) $email->html, "mail $i html is escaped");
        }
        $this->assertSame('Ada Bcc: x@evil.test is waiting for approval', $sent[1]->subject);
    }

    public function test_a_failed_confirm_mail_removes_only_a_member_still_unconfirmed(): void
    {
        $this->site();
        $members = $this->container()->get(Members::class);
        $quick = $this->member('quick@example.com', 'en');
        $slow = $this->member('slow@example.com', 'en');
        $c = $this->container();
        $c->instance(Notifier::class, new Notifier($c->get(TransportFactory::class), [])); // every send fails
        $mailer = $this->mailer(); // resolved after the Notifier was replaced
        foreach ([$quick, $slow] as $m) {
            $id = (string) $m->getId();
            $mailer->confirmLater($m, 'tok', static function () use ($members, $id): void {
                $members->removeIfUnconfirmed($id);
            });
        }
        // Before the queue is flushed, "quick" confirms (an earlier mail's link, another request).
        $members->confirm($members->find((string) $quick->getId()) ?? $this->fail('gone'));

        $c->get(Notifier::class)->flushDeferred();

        $kept = $members->find((string) $quick->getId());
        $this->assertNotNull($kept, 'confirmed meanwhile: kept');
        $this->assertSame(MemberState::Waiting, MemberState::fromRecord($kept));
        $this->assertNull($members->find((string) $slow->getId()), 'still unconfirmed: removed');
        $this->assertFalse($members->removeIfUnconfirmed('no-such-id'));
    }

    public function test_mails_work_without_i18n_config_or_lang_dir(): void
    {
        $this->site();
        unlink($this->dir . '/config/i18n.php');
        $m = $this->member('ada@example.com', 'en');

        $this->mailer()->resetLater($m, 'ptok');
        $this->flush();

        $this->assertSame('Reset your password', $this->onlyMail()->subject);
        $this->assertSame('Votre compte est prêt', $this->container()->get(Translator::class)->get('members.mail.accepted.subject', [], 'fr'));
    }

    // --- helpers -------------------------------------------------------------------------------

    /**
     * @param list<string> $locales
     * @param string $members config/members.php as PHP array source
     * @param string|null $from config/notify.php channels.email.from
     */
    private function site(array $locales = ['en', 'de', 'fr'], string $members = '[]', ?string $from = null): void
    {
        $this->flow = null;
        $this->scaffold(locales: $locales);
        $this->write('config/members.php', "<?php\nreturn {$members};\n");
        if ($from !== null) {
            $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => " . var_export($from, true) . "]]];\n");
        }
        $this->migrateAndApply();
    }

    private function member(string $email, string $lang, string $name = 'Ada'): DynamicRecord
    {
        return $this->container()->get(Members::class)->register($email, $name, 'correct horse battery', $lang, []);
    }

    private function mailer(): MemberMailer
    {
        return $this->container()->get(MemberMailer::class);
    }

    private function flush(): void
    {
        $this->container()->get(Notifier::class)->flushDeferred();
    }

    /** @return list<Email> */
    private function mails(): array
    {
        $sent = $this->container()->get(TransportFactory::class)->get('mail')->sent();

        return array_values(array_map(static fn ($s) => $s->message, $sent));
    }

    private function onlyMail(): Email
    {
        $mails = $this->mails();
        $this->assertCount(1, $mails);

        return $mails[0];
    }
}
