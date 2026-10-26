<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;
use Preflow\Members\Members;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;

/**
 * The members admin in the admin's language: the "members-admin" group (scope admin, German
 * in "du") for the list, the Decision panel, the flashes, the settings form, the permissions
 * and the seeded roles. Its files are the members package's (lang/), declared by members.
 */
final class MembersAdminTranslationTest extends TestCase
{
    use MembersScaffold;

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator('/no/such/lang', $locale, 'en');
        $translator->addDefaults('members-admin', dirname(__DIR__, 3) . '/members/lang');

        return $translator;
    }

    public function test_the_admin_group_has_the_same_keys_in_en_de_and_fr_and_german_says_du(): void
    {
        $translator = self::translator('en');
        $keys = array_keys($translator->entries('members-admin', 'en'));
        foreach (['de', 'fr'] as $locale) {
            $this->assertEqualsCanonicalizing($keys, array_keys($translator->entries('members-admin', $locale)), $locale);
        }
        foreach ($translator->entries('members-admin', 'de') as $key => $text) {
            $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihr|Ihre|Ihren|Ihnen)\b/u', $text, $key);
            $this->assertStringNotContainsString(' — ', $text, $key . ': German uses the spaced en dash " – "');
        }
        foreach (['list.state', 'decision.title', 'decision.accept_send', 'permissions.access', 'roles.members.pending', 'types.members_settings.label', 'fields.members_settings.registration_open.label'] as $key) {
            $this->assertContains('members-admin.' . $key, $keys, $key);
        }
    }

    #[RunInSeparateProcess]
    public function test_the_member_list_and_editor_speak_german_in_du(): void
    {
        $this->scaffold();
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'de'];\n");
        $this->migrateAndApply();
        $this->flow = $this->app();
        $members = $this->flow->container()->get(Members::class);
        $m = $members->register('ada@example.com', 'Ada Lovelace', 'correct horse battery', 'de', []);
        $members->confirm($m);
        $members->decide($m, 'pending');
        $this->signIn();

        $list = html_entity_decode($this->admin('/folio/member'), ENT_QUOTES);
        $this->assertMatchesRegularExpression('~<th[^>]*>\s*Status\s*</th>~', $list);
        $this->assertMatchesRegularExpression('~folio-badge[^"]*"[^>]*>Wartet<~', $list);
        $this->assertDoesNotMatchRegularExpression('~>\s*(State|Waiting)\s*<~', $list);

        $edit = html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES);
        foreach (['Entscheidung', 'Annehmen und senden', 'Ablehnen und senden', 'Nachricht an Ada Lovelace', 'Wird auf Deutsch gesendet.', 'Registriert am '] as $text) {
            $this->assertStringContainsString($text, $edit);
        }
        $shown = (new \DateTimeImmutable((string) $members->find($m->getId())?->get('registered_at')))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
        $this->assertStringContainsString('Registriert am ' . $shown, $edit, 'the decision panel writes the date the German way');
        $this->assertStringContainsString($shown, $list, 'so does the list');
        foreach (['Decision', 'Accept and send', 'Decline and send', 'Message to', 'Sent in', 'Registered ', 'Waiting for approval', 'Members settings'] as $english) {
            $this->assertStringNotContainsString($english, $edit);
        }

        $response = $this->postFrom('/folio/member/' . $m->getId() . '/edit', '/folio/member/' . $m->getId() . '/_action/accept', ['message' => '  ']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('Die Nachricht ist leer.', html_entity_decode($this->admin('/folio/member/' . $m->getId() . '/edit'), ENT_QUOTES));

        $settings = html_entity_decode($this->admin('/folio/members_settings'), ENT_QUOTES);
        foreach (['Mitglieder-Einstellungen', 'Registrierung offen', 'Nachricht beim Annehmen'] as $text) {
            $this->assertStringContainsString($text, $settings);
        }
        $this->assertStringNotContainsString('Registration open', $settings);
    }

    #[RunInSeparateProcess]
    public function test_an_empty_member_list_says_so_in_proper_german(): void
    {
        $this->scaffold();
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'locale' => 'de'];\n");
        $this->migrateAndApply();
        $this->flow = $this->app();
        $this->signIn();

        $this->assertStringContainsString('Noch keine Mitglieder.', $this->admin('/folio/member'));
    }
}
