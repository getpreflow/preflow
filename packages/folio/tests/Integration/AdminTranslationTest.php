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

    /** Part 2: English source texts of the asset, users, roles, settings, extensions, cache and error screens. */
    private const array ENGLISH_PART2 = ['Replace', 'Earlier versions', 'Edit image', 'Rotate left', 'Rotate right', 'Clear cache', 'Not used anywhere.', 'Upload', 'Details', 'Usage', 'Alt text', 'Grid', 'List', 'Select', 'All kinds', 'Images', 'Videos', 'Documents', 'Apply crop', 'Image crop', 'Remove crop', 'Ratio', 'Free', 'Formats', 'Download original', 'Choose an asset', 'Files', 'Size', 'Added', 'Clear all', 'Reset OPcache', 'Memory used', 'Hit rate', 'Installed', 'Not installed', 'Enabled', 'Disabled', 'Enable', 'No access', 'Ask an administrator if you need it.', "You don't have access to this.", 'Not found', 'Folio extensions', 'Folio blocks'];

    /** @return array<string, array{string, list<string>, list<string>}> locale, texts that must show, English words that are the same in it */
    public static function localesPart2(): array
    {
        return [
            'German' => ['de', ['3 Dateien', 'Ersetzen', 'Frühere Versionen', 'Bild bearbeiten', 'Nach links drehen', 'Nirgends verwendet.', 'Cache leeren', 'Kein Zugriff', 'Erweiterungen'], ['Filter', 'Details', 'Formats', 'Videos']],
            'French' => ['fr', ['3 fichiers', 'Remplacer', 'Versions précédentes', 'Modifier l’image', 'Pivoter à gauche', 'Utilisé nulle part.', 'Vider le cache', 'Accès refusé', 'Extensions'], ['Images', 'Formats', 'Documents']],
        ];
    }

    /** Three PNGs in the library; the first replaced once (so it has an earlier version). @return list<string> their ids */
    private function seedAssets(): array
    {
        $library = $this->flow->container()->get(\Preflow\Media\MediaLibrary::class);
        $ids = [];
        foreach (['red' => [255, 0, 0], 'green' => [0, 255, 0], 'blue' => [0, 0, 255]] as $name => [$r, $g, $b]) {
            $image = imagecreatetruecolor(160, 120);
            imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
            $file = $this->dir . '/' . $name . '.png';
            imagepng($image, $file);
            $ids[] = $library->importFile($file, $name . '.png', null)->id;
        }
        $library->replace($ids[0], $this->dir . '/green.png', null);

        return $ids;
    }

    /** @param list<string> $same */
    private function assertTranslatedPart2(string $html, string $page, array $same): void
    {
        $this->assertTranslated($html, $page, $same);
        $text = preg_replace('~<script(?![^>]*application/json)[^>]*>.*?</script>~s', '', $html) ?? '';
        foreach (array_diff(self::ENGLISH_PART2, $same) as $english) {
            $this->assertDoesNotMatchRegularExpression('~(?<![\w-])' . preg_quote($english, '~') . '(?![\w-])~u', $text, "{$page}: \"{$english}\" is still English");
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('localesPart2')]
    #[\PHPUnit\Framework\Attributes\RequiresPhpExtension('gd')]
    public function test_the_asset_screens_speak_the_users_language(string $locale, array $expected, array $same): void
    {
        $ids = $this->seedAssets();
        $this->signInAs($locale);

        $pages = [
            'library (grid)' => $this->html('/folio/assets'),
            'library (list)' => $this->html('/folio/assets?view=list'),
            'asset page' => $this->html('/folio/assets/' . $ids[0]),
            'edit image' => $this->html('/folio/assets/' . $ids[0] . '/edit'),
            'picker' => $this->html('/folio/assets?picker=1&multiple=0'),
        ];
        $this->assertStringContainsString('folio-asset-list', $pages['library (list)'], 'the list view is there');
        $this->assertStringContainsString('folio-asset-version-list', $pages['asset page'], 'the versions are there');
        $this->assertStringContainsString('data-folio-image-editor', $pages['edit image'], 'the editor is there');
        $this->assertStringContainsString('data-folio-pick', $pages['picker'], 'the picker is there');
        foreach ($pages as $name => $html) {
            $this->assertStringContainsString('<html lang="' . $locale . '">', $html, $name);
            $this->assertTranslatedPart2($html, $name, $same);
            $this->assertStringContainsString('id="folio-i18n"', $html, "{$name}: the JS dictionary");
        }
        $all = implode("\n", $pages);
        foreach (array_slice($expected, 0, 6) as $text) {
            $this->assertStringContainsString($text, $all);
        }
        $this->assertStringContainsString($locale === 'de' ? 'Nirgends verwendet.' : 'Utilisé nulle part.', $pages['asset page']);
        // The library's dates in the language's pattern.
        $pattern = $locale === 'de' ? '\d{2}\.\d{2}\.\d{4}' : '\d{2}/\d{2}/\d{4}';
        $this->assertMatchesRegularExpression('~<td>' . $pattern . '</td>~', $pages['library (list)']);
    }

    #[RunInSeparateProcess]
    #[DataProvider('localesPart2')]
    public function test_users_settings_extensions_cache_and_errors_speak_the_users_language(string $locale, array $expected, array $same): void
    {
        $this->write('config/models/settings.json', (string) json_encode([
            'key' => 'settings', 'table' => 'settings', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Site', 'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string']],
        ]));
        $this->write('app/Folio/Hello/Extension.php', "<?php\nnamespace App\\Folio\\Hello;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'hello', label: 'Hello', version: '1.0.0')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        require_once $this->dir . '/app/Folio/Hello/Extension.php'; // the test runner's autoloader doesn't know the site's app/
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'extensions' => ['allow_admin_changes' => false]];\n");
        $this->flow = $this->app();
        $this->signInAs($locale);
        $ada = $this->staff('ada@site.test');

        $pages = [
            'users' => $this->html('/folio/staff'),
            'user' => $this->html('/folio/staff/' . $ada->getId() . '/edit'),
            'site settings' => $this->html('/folio/settings'),
            'extensions' => $this->html('/folio/extensions'),
            'cache' => $this->html('/folio/cache'),
        ];
        $forbidden = $this->post('/folio/extensions/hello', ['enabled' => '1']);
        $this->assertSame(403, $forbidden->getStatusCode());
        $pages['forbidden (403)'] = (string) $forbidden->getBody();
        $notFound = $this->send('GET', '/folio/assets/nope');
        $this->assertSame(404, $notFound->getStatusCode());
        $pages['not found (404)'] = (string) $notFound->getBody();
        $this->assertSame($locale === 'de' ? 'Nicht gefunden' : 'Introuvable', $pages['not found (404)']);

        $this->assertStringContainsString('hello', $pages['extensions'], 'the extension is listed');
        foreach ($pages as $name => $html) {
            if ($name !== 'not found (404)') {
                $this->assertStringContainsString('<html lang="' . $locale . '">', $html, $name);
            }
            // The users' field labels ("Email", from the field name) come from the site's model file, not Folio's texts.
            $this->assertTranslatedPart2($html, $name, $name === 'users' || $name === 'user' ? [...$same, 'Email'] : $same);
        }
        $all = implode("\n", $pages);
        foreach (array_slice($expected, 6) as $text) {
            $this->assertStringContainsString($text, $all);
        }

        // The cache screen's flash after clearing all.
        $this->post('/folio/cache/clear', ['all' => '1']);
        $this->assertStringContainsString($locale === 'de' ? 'Alle Caches geleert' : 'Tous les caches vidés', $this->html('/folio/cache'));
        // Developer-facing bodies of the block editor and the singleton delete.
        $this->assertSame($locale === 'de' ? 'Ungültiger Feldname' : 'Nom de champ non valide', (string) $this->send('GET', '/folio/_blocks/x/new?name=1bad')->getBody());
        $this->assertSame($locale === 'de' ? 'Ein Singleton kann nicht gelöscht werden' : 'Un singleton ne peut pas être supprimé', (string) $this->post('/folio/settings/settings/delete', [])->getBody());
    }

    public function test_media_messages_are_mapped_to_folio_texts(): void
    {
        $previous = FolioText::use(self::translator('de'));
        try {
            $this->assertSame('braucht Imagick für farbgetreue Varianten', \Preflow\Folio\Media\MediaMessages::note(\Preflow\Media\MediaLibrary::NOTE_NEEDS_IMAGICK));
            $this->assertSame('a.svg: Dateien vom Typ image/svg+xml sind nicht erlaubt.', \Preflow\Folio\Media\MediaMessages::text('a.svg: files of type image/svg+xml are not allowed.'));
            $this->assertSame('big.png ist größer als die Grenze von 2 MB.', \Preflow\Folio\Media\MediaMessages::text('big.png is larger than the 2 MB limit.'));
            $this->assertSame('x.pdf: Ein Bild kann nur durch ein anderes Bild ersetzt werden.', \Preflow\Folio\Media\MediaMessages::text('x.pdf: a image can only be replaced by another image.'));
            $this->assertSame('Something else entirely.', \Preflow\Folio\Media\MediaMessages::text('Something else entirely.'));
            $this->assertSame('custom note', \Preflow\Folio\Media\MediaMessages::note('custom note'));
        } finally {
            FolioText::use($previous);
        }
        $this->assertSame('a.svg: files of type image/svg+xml are not allowed.', \Preflow\Folio\Media\MediaMessages::text('a.svg: files of type image/svg+xml are not allowed.'));
    }

    public function test_the_image_editor_script_holds_no_english_ui_text(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/image-editor.js');
        $code = (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $js);
        $this->assertDoesNotMatchRegularExpression("~['\"][A-Z][a-z]+( [a-z]+)*[.!?]?['\"]~", $code, 'a UI text in image-editor.js');
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

    public function test_german_writes_a_spaced_en_dash_and_natural_wording(): void
    {
        foreach (self::translator('de')->entries('folio', 'de') as $key => $text) {
            $this->assertStringNotContainsString(' — ', $text, $key . ': German uses the spaced en dash " – "');
        }
        $de = self::translator('de');
        $this->assertSame('Als Entwurf gespeichert – nicht veröffentlicht.', $de->get('folio.form.not_published'));
        $this->assertSame('Wie im System', $de->get('folio.layout.theme_system'));
        foreach (['fields', 'js'] as $area) {
            $this->assertSame('Nach oben', $de->get("folio.{$area}.up"), $area);
            $this->assertSame('Nach unten', $de->get("folio.{$area}.down"), $area);
        }
        $this->assertSame('Folio – Inhalte mit', $de->get('folio.auth.powered_by'));
    }

    private static function translator(string $locale): Translator
    {
        $translator = new Translator(sys_get_temp_dir() . '/folio-no-site-lang', $locale, 'en');
        $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');

        return $translator;
    }
}
