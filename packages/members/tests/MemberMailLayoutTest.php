<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DeletePlan;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeLabels;
use Preflow\Members\MemberLinks;
use Preflow\Members\MemberMailer;
use Preflow\Members\Members;
use Preflow\Notify\Email;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;
use Preflow\Members\Tests\Support\MembersScaffold;

/** The member mails go through notify's mail layer: neutral accent, no mention of Folio, the owner's command or link, types by label. */
final class MemberMailLayoutTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_mails_never_name_folio(): void
    {
        foreach (['en', 'de', 'fr'] as $i => $lang) {
            foreach ([false, true] as $withLink) {
                $this->site(array_values(array_unique([$lang, 'en', 'de', 'fr'])), job: true);
                if ($withLink) {
                    $this->links();
                }
                $m = $this->member('ada@example.test', $lang);
                $mailer = $this->mailer();
                $plan = new DeletePlan([], [], ['job' => 2], 'member', []);

                $mailer->confirmLater($m, 't');
                $mailer->existingLater($m, 'r');
                $mailer->resetLater($m, 'r');
                $mailer->noticeLater($m, 'owner@site.test');
                $this->flush();
                $mailer->accepted($m, $mailer->defaultMessage($m, 'accepted'));
                $mailer->declined($m, $mailer->defaultMessage($m, 'declined'));
                $mailer->deletionRefused($m, 'owner@site.test', $plan, 'restricted');
                $mailer->deletionRefused($m, 'owner@site.test', $plan, 'listener');

                $mails = $this->mails();
                $this->assertCount(7 + 1, $mails);
                foreach ($mails as $n => $mail) {
                    foreach ([$mail->subject, $mail->text, (string) $mail->html] as $part => $text) {
                        $this->assertStringNotContainsStringIgnoringCase('folio', $text, "$lang link=$withLink mail $n part $part");
                    }
                }
                $this->removeScaffold();
            }
        }
    }

    public function test_notice_without_links_names_the_command(): void
    {
        $this->site();
        $this->mailer()->noticeLater($this->member('ada@example.test', 'en'), 'owner@site.test');
        $this->flush();

        $this->assertStringEndsWith("php preflow members:decide 'ada@example.test' accept (or decline)", $this->onlyMail()->text);
    }

    public function test_notice_links_the_decision_url(): void
    {
        $this->site();
        $this->links();
        $this->mailer()->noticeLater($this->member('ada@example.test', 'en'), 'owner@site.test');
        $this->flush();

        $mail = $this->onlyMail();
        $this->assertStringEndsWith('Decide here: https://site.test/admin/m/1', $mail->text);
        $this->assertStringContainsString('<a href="https://site.test/admin/m/1"', (string) $mail->html);
    }

    public function test_default_accent_is_neutral(): void
    {
        $this->site();
        $this->mailer()->confirmLater($this->member('ada@example.test', 'en'), 't');
        $this->flush();

        $html = (string) $this->onlyMail()->html;
        $this->assertStringContainsString('#1a1a1a', $html);
        $this->assertStringNotContainsString('#d42a34', $html);
    }

    public function test_members_accent_overrides_notify(): void
    {
        $this->site(members: "['mail' => ['accent' => '#0f766e']]");
        $this->mailer()->confirmLater($this->member('ada@example.test', 'en'), 't');
        $this->flush();

        $this->assertStringContainsString('background:#0f766e', (string) $this->onlyMail()->html);
    }

    public function test_notify_accent_applies_without_a_members_accent(): void
    {
        $this->site(notifyMail: "['accent' => '#336699']");
        $this->mailer()->confirmLater($this->member('ada@example.test', 'en'), 't');
        $this->flush();

        $this->assertStringContainsString('background:#336699', (string) $this->onlyMail()->html);
    }

    public function test_site_layout_still_works(): void
    {
        $this->site(members: "['mail' => ['layout' => 'resources/mail/site.twig', 'accent' => '#0f766e']]");
        $this->write('resources/mail/site.twig', '<h1 style="color:{{ accent }}">{{ subject }}</h1>{% for p in paragraphs %}<p>{{ p|raw }}</p>{% endfor %}{% if action is defined and action %}<a class="go" href="{{ action.url }}">{{ action.label }}</a>{% endif %}');
        $this->mailer()->confirmLater($this->member('ada@example.test', 'en'), 'tok');
        $this->flush();

        $html = (string) $this->onlyMail()->html;
        $this->assertStringContainsString('<h1 style="color:#0f766e">Confirm your email address</h1>', $html);
        $this->assertStringContainsString('<a class="go" href="https://site.test/members/confirm/tok">Confirm email address</a>', $html);
        $this->assertStringContainsString('<p>Hi Ada,</p>', $html);
    }

    public function test_refusal_mail_names_types_by_label(): void
    {
        $this->site(['en', 'de'], job: true);
        $plan = new DeletePlan([], [], ['job' => 2], 'member', []);
        $this->mailer()->deletionRefused($this->member('ada@example.test', 'en'), 'owner@site.test', $plan, 'restricted');

        $this->assertStringContainsString('Jobs: 2', $this->onlyMail()->text);
    }

    public function test_refusal_mail_uses_a_bound_type_labels_in_the_owners_language(): void
    {
        $this->site(['de', 'en'], job: true);
        $this->container()->instance(TypeLabels::class, new class implements TypeLabels {
            public function label(string $type, string $locale): string
            {
                return $locale === 'de' ? 'Stellen' : 'Positions';
            }
        });
        $plan = new DeletePlan([], [], ['job' => 2, 'page' => 1], 'member', []);
        $this->mailer()->deletionRefused($this->member('ada@example.test', 'en'), 'owner@site.test', $plan, 'restricted');

        $this->assertStringContainsString('Stellen: 2, Stellen: 1', $this->onlyMail()->text);
    }

    public function test_refusal_mail_falls_back_to_the_key_without_a_plain_label(): void
    {
        $this->site(job: true);
        $this->write('config/models/job.json', (string) json_encode([
            'key' => 'job', 'table' => 'job', 'storage' => 'json', 'id_field' => 'uuid',
            'label' => ['en' => 'Jobs'], 'fields' => ['title' => ['type' => 'string']],
        ]));
        $plan = new DeletePlan([], [], ['job' => 2, 'gone' => 1], 'member', []);
        $this->mailer()->deletionRefused($this->member('ada@example.test', 'en'), 'owner@site.test', $plan, 'restricted');

        $this->assertStringContainsString('job: 2, gone: 1', $this->onlyMail()->text);
    }

    // --- helpers -------------------------------------------------------------------------------

    /** @param list<string> $locales */
    private function site(array $locales = ['en', 'de', 'fr'], string $members = '[]', bool $job = false, string $notifyMail = '[]'): void
    {
        $this->flow = null;
        $this->scaffold(locales: $locales);
        $this->write('config/members.php', "<?php\nreturn {$members};\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail']], 'mail' => {$notifyMail}];\n");
        if ($job) {
            $this->write('config/models/job.json', (string) json_encode([
                'key' => 'job', 'table' => 'job', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Jobs',
                'fields' => ['title' => ['type' => 'string']],
            ]));
        }
        $this->migrate();
    }

    private function links(): void
    {
        $this->container()->instance(MemberLinks::class, new class implements MemberLinks {
            public function decisionUrl(DynamicRecord $member): ?string
            {
                return 'https://site.test/admin/m/1';
            }
        });
    }

    private function member(string $email, string $lang): DynamicRecord
    {
        return $this->container()->get(Members::class)->register($email, 'Ada', 'correct horse battery', $lang, []);
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
        return array_values(array_map(static fn ($s) => $s->message, $this->container()->get(TransportFactory::class)->get('mail')->sent()));
    }

    private function onlyMail(): Email
    {
        $mails = $this->mails();
        $this->assertCount(1, $mails);

        return $mails[0];
    }
}
