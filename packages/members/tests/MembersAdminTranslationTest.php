<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\I18n\Translator;
use Preflow\Members\Members;
use Preflow\Members\Tests\Support\MemberFlows;

/**
 * The members admin in the admin's language: the "members-admin" group (scope admin, German
 * in "du") for the list, the Decision panel, the flashes, the settings form, the permissions
 * and the seeded roles. The public "members" group is untouched: the German registration
 * page is byte for byte the one of v0.36.0 ("Sie").
 */
final class MembersAdminTranslationTest extends TestCase
{
    use MemberFlows;

    /** The registration page and its error page in German, as v0.36.0 renders them (CSRF token blanked). */
    private const string SNAPSHOT = __DIR__ . '/Fixtures/register-de-v0.36.0.html';

    protected function tearDown(): void
    {
        if (($this->dir ?? '') !== '') {
            $this->removeScaffold();
        }
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator('/no/such/lang', $locale, 'en');
        $translator->addDefaults('members-admin', dirname(__DIR__) . '/lang');

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

    #[RunInSeparateProcess]
    public function test_the_german_registration_page_is_the_one_of_v0_36_0(): void
    {
        $this->flowSite();

        $page = $this->send('GET', '/de/members/register');
        $errors = $this->post('/de/members/register', ['name' => '', 'email' => 'nope', 'password' => 'short']);
        $this->assertSame(200, $page->getStatusCode());
        $both = self::stable((string) $page->getBody()) . "\n<!-- errors -->\n" . self::stable((string) $errors->getBody());
        if (getenv('PREFLOW_WRITE_SNAPSHOT') === '1') {
            file_put_contents(self::SNAPSHOT, $both);
        }

        $this->assertStringContainsString('Bitte geben Sie', $both);
        $this->assertSame((string) file_get_contents(self::SNAPSHOT), $both);
    }

    private static function stable(string $html): string
    {
        return (string) preg_replace(['/(name="_csrf_token" value=")[^"]+/', '/(nonce=")[^"]+/', '/(Nonce":")[^"]+/'], '$1…', $html);
    }
}
