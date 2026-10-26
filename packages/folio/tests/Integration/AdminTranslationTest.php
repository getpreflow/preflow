<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\FolioText;
use Preflow\Folio\Http\AdminFormat;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;

/**
 * Folio's own words in the user's interface language: the shell, the dashboard, a content list
 * with its filters and bulk bar, a record's editor with revisions, the account page and the
 * signed-out pages. No raw key and none of the English source texts shows; admin.js takes its
 * words from the JSON dictionary of the page; dates and counts follow the language.
 */
final class AdminTranslationTest extends TestCase
{
    use StaffScaffold;

    /** English source texts of these screens that must not show in another language. */
    private const array ENGLISH = ['Save', 'Delete', 'Search', 'Dashboard', 'Filter', 'Sign in', 'Forgot your password?', 'Forgot password?', 'Your account', 'Log out', 'Revisions', 'Publish', 'Cancel', 'Clear', 'Export', 'New', 'Edit', 'Password', 'Email', 'Change password', 'Back to sign in', 'Send link', 'records'];

    protected function setUp(): void
    {
        $this->staffSite();
        $this->write('config/models/post.json', (string) json_encode([
            'key' => 'post', 'table' => 'post', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Posts', 'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string'],
                'kind' => ['type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']],
                'featured' => ['type' => 'boolean'],
            ],
            'list' => ['filters' => ['kind', 'featured'], 'bulk' => ['featured']],
        ]));
        $this->flow = $this->app();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    /** @return array<string, array{string, list<string>, list<string>}> locale, texts that must show, English words that are the same in it */
    public static function locales(): array
    {
        return [
            'German' => ['de', ['Übersicht', 'Speichern', 'Suchen', 'Abmelden', '3 Einträge'], ['Filter']],
            'French' => ['fr', ['Tableau de bord', 'Enregistrer', 'Rechercher', 'Se déconnecter', '3 entrées'], []],
        ];
    }

    /** @param array<string, string> $headers */
    private function get(string $uri, array $headers = []): string
    {
        $request = (new Psr17Factory())->createServerRequest('GET', $uri, ['REMOTE_ADDR' => $this->remoteAddr]);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return (string) $this->flow->handle($request)->getBody();
    }

    private function seed(): void
    {
        $dm = $this->dm();
        $type = $dm->getTypeRegistry()->get('post');
        foreach (['p1' => 'First', 'p2' => 'Second', 'p3' => 'Third'] as $id => $title) {
            $dm->saveType(DynamicRecord::fromArray($type, ['uuid' => $id, 'title' => $title, 'kind' => 'a', 'featured' => false]), false);
        }
        $record = $dm->findType('post', 'p1');
        $record->set('title', 'First, published');
        $this->flow->container()->get(Publisher::class)->publish($record, 'old@site.test');
    }

    private function signInAs(string $locale): void
    {
        $ada = $this->makeStaff();
        $this->flow->container()->get(UserPreferences::class)->setLocale((string) $ada->getId(), $locale);
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    /** @param list<string> $same English words that are also words of $locale */
    private function assertTranslated(string $html, string $page, array $same): void
    {
        $this->assertDoesNotMatchRegularExpression('~folio\.[a-z_]+\.~', $html, "{$page}: a raw key");
        // Only the text people read: no scripts' code, no attribute names; but titles, labels and confirm texts.
        $text = preg_replace('~<script(?![^>]*application/json)[^>]*>.*?</script>~s', '', $html) ?? '';
        foreach (array_diff(self::ENGLISH, $same) as $english) {
            $this->assertDoesNotMatchRegularExpression('~(?<![\w-])' . preg_quote($english, '~') . '(?![\w-])~u', $text, "{$page}: \"{$english}\" is still English");
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('locales')]
    public function test_the_signed_in_screens_speak_the_users_language(string $locale, array $expected, array $same): void
    {
        $this->seed();
        $this->signInAs($locale);

        $pages = [
            'dashboard' => $this->html('/folio'),
            'list' => $this->html('/folio/post'),
            'editor' => $this->html('/folio/post/p1/edit'),
            'account' => $this->html('/folio/_account'),
        ];
        $this->assertStringContainsString('data-folio-bulk', $pages['list'], 'the bulk bar is there');
        $this->assertStringContainsString('folio-filter-menu', $pages['list'], 'the filters are there');
        $this->assertStringContainsString('folio-revision', $pages['editor'], 'the revisions are there');
        foreach ($pages as $name => $html) {
            $this->assertStringContainsString('<html lang="' . $locale . '">', $html, $name);
            $this->assertTranslated($html, $name, $same);
        }

        $all = implode("\n", $pages);
        foreach ($expected as $text) {
            $this->assertStringContainsString($text, $all);
        }
        // The revision's date in the language's pattern.
        $pattern = $locale === 'de' ? '\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}' : '\d{2}/\d{2}/\d{4} \d{2}:\d{2}';
        $this->assertMatchesRegularExpression('~<span class="folio-revision-date">' . $pattern . '</span>~', $pages['editor']);
    }

    #[RunInSeparateProcess]
    #[DataProvider('locales')]
    public function test_the_signed_out_pages_follow_the_browser(string $locale, array $expected, array $same): void
    {
        foreach (['sign-in' => '/folio/login', 'forgot' => '/folio/forgot'] as $name => $uri) {
            $html = $this->get($uri, ['Accept-Language' => $locale]);
            $this->assertStringContainsString('<html lang="' . $locale . '">', $html, $name);
            $this->assertTranslated($html, $name, $same);
        }
        $this->assertStringContainsString($locale === 'de' ? 'Passwort vergessen?' : 'Mot de passe oublié ?', $this->get('/folio/login', ['Accept-Language' => $locale]));
    }

    #[RunInSeparateProcess]
    #[DataProvider('locales')]
    public function test_admin_js_gets_its_words_from_the_page(string $locale): void
    {
        $this->signInAs($locale);
        $html = $this->html('/folio');

        $this->assertMatchesRegularExpression('~<script type="application/json" id="folio-i18n" nonce="[^"]+">(.*?)</script>~s', $html);
        preg_match('~<script type="application/json" id="folio-i18n" nonce="[^"]+">(.*?)</script>~s', $html, $m);
        $texts = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($locale === 'de' ? 'Diesen Eintrag löschen?' : 'Supprimer cette entrée ?', $texts['confirm_delete'] ?? null);
        $this->assertStringNotContainsString('</script', $m[1]);
    }

    public function test_admin_js_and_the_preview_editor_hold_no_english_ui_text(): void
    {
        $root = dirname(__DIR__, 2) . '/assets/';
        foreach (['admin.js', 'preview-editor.js'] as $file) {
            $js = (string) file_get_contents($root . $file);
            foreach (["'Close'", "'Remove'", "'Undo'", "'Copied'", "'Delete this record?'", "'Selected'", "'No matches'", "'Block'", "n + ' selected'", "'Not saved", "' removed'", "' of '"] as $english) {
                $this->assertStringNotContainsString($english, $js, "{$file}: {$english}");
            }
        }
        $this->assertStringContainsString('folioT(', (string) file_get_contents($root . 'admin.js'));
    }

    /** @return array<string, array{string, bool, string, string}> */
    public static function dates(): array
    {
        return [
            'German, intl' => ['de', true, '26.10.2026', '26.10.2026 15:05'],
            'German, no intl' => ['de', false, '26.10.2026', '26.10.2026 15:05'],
            'French, intl' => ['fr', true, '26/10/2026', '26/10/2026 15:05'],
            'French, no intl' => ['fr', false, '26/10/2026', '26/10/2026 15:05'],
            'English, intl' => ['en', true, 'Oct 26, 2026', 'Oct 26, 2026 15:05'],
            'English, no intl' => ['en', false, 'Oct 26, 2026', 'Oct 26, 2026 15:05'],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates_follow_the_interface_language(string $locale, bool $intl, string $date, string $datetime): void
    {
        if ($intl && !extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl');
        }
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Zurich'); // the app's zone (Application sets it from app.timezone)
        try {
            $format = new AdminFormat(self::translator($locale), $intl);
            $this->assertSame($date, $format->date('2026-10-26T14:05:00Z'));
            $this->assertSame($datetime, $format->date('2026-10-26T14:05:00Z', 'datetime'));
            $this->assertSame($date, $format->date(new \DateTimeImmutable('2026-10-26 15:05')));
            $this->assertSame('', $format->date(''));
            $this->assertSame('', $format->date(null));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    /** @return array<string, array{string, bool, string}> */
    public static function numbers(): array
    {
        return [
            'German, intl' => ['de', true, '12.345,5'],
            'German, no intl' => ['de', false, '12.345,5'],
            'English, no intl' => ['en', false, '12,345.5'],
            'French, no intl' => ['fr', false, "12\u{202F}345,5"],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_follow_the_interface_language(string $locale, bool $intl, string $expected): void
    {
        if ($intl && !extension_loaded('intl')) {
            $this->markTestSkipped('ext-intl');
        }
        $format = new AdminFormat(self::translator($locale), $intl);
        $this->assertSame($expected, $format->number(12345.5));
        $this->assertSame(str_replace([',5', '.5'], '', $expected), $format->number(12345));
    }

    public function test_counts_use_the_plural_forms(): void
    {
        $de = self::translator('de');
        $fr = self::translator('fr');
        $en = self::translator('en');
        $this->assertSame('3 Einträge', $de->choice('folio.dashboard.records', 3));
        $this->assertSame('1 Eintrag', $de->choice('folio.dashboard.records', 1));
        $this->assertSame('3 entrées', $fr->choice('folio.dashboard.records', 3));
        $this->assertSame('0 entrée', $fr->choice('folio.dashboard.records', 0));
        $this->assertSame('1 record', $en->choice('folio.dashboard.records', 1));
        $this->assertSame('0 records', $en->choice('folio.dashboard.records', 0));
    }

    public function test_folio_text_is_english_outside_an_admin_request_and_the_users_language_inside(): void
    {
        $this->assertSame('Save', FolioText::get('folio.common.save'));
        $previous = FolioText::use(self::translator('de'));
        try {
            $this->assertSame('Speichern', FolioText::get('folio.common.save'));
            $this->assertSame('de', FolioText::locale());
        } finally {
            FolioText::use($previous);
        }
        $this->assertSame('Save', FolioText::get('folio.common.save'));
    }

    public function test_every_language_has_every_key(): void
    {
        $keys = [];
        foreach (['en', 'de', 'fr'] as $locale) {
            $keys[$locale] = array_keys(self::translator($locale)->entries('folio', $locale));
            sort($keys[$locale]);
        }
        $this->assertSame([], array_values(array_diff($keys['en'], $keys['de'])), 'missing in de');
        $this->assertSame([], array_values(array_diff($keys['en'], $keys['fr'])), 'missing in fr');
        $this->assertSame([], array_values(array_diff($keys['de'], $keys['en'])), 'only in de');
        $this->assertSame([], array_values(array_diff($keys['fr'], $keys['en'])), 'only in fr');
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator(sys_get_temp_dir() . '/folio-no-site-lang', $locale, 'en');
        $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');

        return $translator;
    }
}
