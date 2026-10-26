<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Users\UserPreferences;

/**
 * Labels from model files in the user's interface language: a key in the site's language
 * file (site.types.app.label, site.fields.app.kind.label, …) wins, then a map in the model
 * file (the user's language, the site default, English), then the plain string, then the
 * humanized field name. Nav, list headers, filters, bulk bar, editor and export agree.
 */
final class ModelLabelsTest extends TestCase
{
    use StaffScaffold;

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    /** @param string|array<string, string> $label */
    private function site(string|array $label, ?string $default = null): void
    {
        $this->staffSite(locale: $default);
        $this->write('config/models/app.json', (string) json_encode([
            'key' => 'app', 'table' => 'app', 'storage' => 'json', 'id_field' => 'uuid', 'label' => $label,
            'fields' => [
                'title' => ['type' => 'string', 'label' => 'Title'],
                'kind' => [
                    'type' => 'select',
                    'label' => ['en' => 'Kind', 'de' => 'Art'],
                    'help' => 'Pick one',
                    'options' => ['web' => ['en' => 'Web', 'de' => 'Netz'], 'cli' => 'CLI'],
                ],
                'release_date' => ['type' => 'date'],
            ],
            'list' => ['columns' => ['title', 'kind'], 'filters' => ['kind'], 'bulk' => ['kind']],
        ]));
    }

    private function signInAs(string $locale): void
    {
        $this->flow = $this->app();
        $ada = $this->makeStaff();
        $this->flow->container()->get(UserPreferences::class)->setLocale((string) $ada->getId(), $locale);
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
        $type = $this->dm()->getTypeRegistry()->get('app');
        $this->dm()->saveType(DynamicRecord::fromArray($type, ['uuid' => 'a1', 'title' => 'Folio', 'kind' => 'web']), false);
    }

    /** The sidebar's markup. */
    private static function navLabels(string $html): string
    {
        preg_match('~<nav[^>]*>.*?</nav>~s', $html, $m);

        return $m[0] ?? '';
    }

    #[RunInSeparateProcess]
    public function test_a_plain_label_shows_as_written(): void
    {
        $this->site('Apps');
        $this->signInAs('fr');
        $this->assertStringContainsString('<span>Apps</span>', self::navLabels($this->html('/folio')));
    }

    #[RunInSeparateProcess]
    public function test_a_key_in_the_site_file_wins(): void
    {
        $this->site('Apps');
        $this->write('lang/fr/site.php', "<?php return ['types' => ['app' => ['label' => 'Applications']], 'fields' => ['app' => ['title' => ['label' => 'Titre']]]];");
        $this->signInAs('fr');

        $list = $this->html('/folio/app');
        $this->assertStringContainsString('<span>Applications</span>', self::navLabels($list));
        $this->assertStringNotContainsString('<span>Apps</span>', $list);
        $this->assertMatchesRegularExpression('~<th[^>]*>.*?Titre.*?</th>~s', $list);
    }

    #[RunInSeparateProcess]
    public function test_a_label_map_picks_the_users_language(): void
    {
        $this->site(['en' => 'Apps', 'de' => 'Anwendungen']);
        $this->signInAs('de');
        $this->assertStringContainsString('<span>Anwendungen</span>', self::navLabels($this->html('/folio')));
    }

    #[RunInSeparateProcess]
    public function test_a_label_map_without_the_users_language_picks_the_site_default(): void
    {
        $this->site(['en' => 'Apps', 'de' => 'Anwendungen'], default: 'de');
        $this->signInAs('fr');
        $this->assertStringContainsString('<span>Anwendungen</span>', self::navLabels($this->html('/folio')));
    }

    #[RunInSeparateProcess]
    public function test_field_help_and_option_labels_follow_the_same_order_everywhere(): void
    {
        $this->site('Apps');
        $this->write('lang/de/site.php', "<?php return [
            'fields' => ['app' => ['kind' => ['help' => 'Wähle eine Art'], 'release_date' => ['label' => 'Erscheinungsdatum']]],
            'options' => ['app' => ['kind' => ['cli' => 'Kommandozeile']]],
        ];");
        $this->signInAs('de');

        $list = $this->html('/folio/app');
        // The column header and the filter: the map's German entry; the filter and bulk options: map and key.
        $this->assertMatchesRegularExpression('~<th[^>]*>.*?Art.*?</th>~s', $list);
        $this->assertMatchesRegularExpression('~folio-filter-label[^>]*>Art<~', $list);
        $this->assertStringContainsString('>Netz</option>', $list);
        $this->assertStringContainsString('Kommandozeile', $list);
        $this->assertMatchesRegularExpression('~<summary[^>]*>[^<]*Art[^<]*</summary>~', $list, 'the bulk "set" menu');
        $this->assertDoesNotMatchRegularExpression('~>(Kind|Web|CLI)<~', $list);
        // A chip of an active filter.
        $this->assertStringContainsString('Art: Netz', $this->html('/folio/app?f%5Bkind%5D=web'));

        $form = $this->html('/folio/app/a1/edit');
        $this->assertStringContainsString('>Art<', $form);
        $this->assertStringContainsString('Wähle eine Art', $form);
        $this->assertStringNotContainsString('Pick one', $form);
        $this->assertStringContainsString('>Netz</option>', $form);
        $this->assertStringContainsString('>Kommandozeile</option>', $form);
        $this->assertStringContainsString('Erscheinungsdatum', $form);
        $this->assertStringContainsString('>Title<', $form, 'a plain label without a key stays');
    }

    #[RunInSeparateProcess]
    public function test_the_export_header_row_uses_the_resolved_labels(): void
    {
        if (!class_exists(\OpenSpout\Writer\XLSX\Writer::class)) {
            $this->markTestSkipped('OpenSpout is not installed');
        }
        $this->site('Apps');
        $this->write('lang/de/site.php', "<?php return ['fields' => ['app' => ['title' => ['label' => 'Titel']]]];");
        $this->signInAs('de');

        $response = $this->send('GET', '/folio/app/_export');
        $this->assertSame(200, $response->getStatusCode());
        $path = $this->dir . '/export.xlsx';
        file_put_contents($path, (string) $response->getBody());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml') . (string) $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();
        $this->assertStringContainsString('Titel', $xml);
        $this->assertStringContainsString('Art', $xml);
        $this->assertStringNotContainsString('>Title<', $xml);
    }

    /** @return array{0: int, 1: string} translations:check on the site */
    private function check(): array
    {
        ob_start();
        try {
            $code = (new \Preflow\I18n\Console\TranslationsCheckCommand(fn () => $this->app()))->execute([]);
        } finally {
            $out = (string) ob_get_clean();
        }

        return [$code, $out];
    }

    #[RunInSeparateProcess]
    public function test_translations_check_needs_no_key_for_the_sites_own_types(): void
    {
        $this->site('Apps');
        $this->write('lang/de/site.php', "<?php return ['types' => ['app' => ['label' => 'Anwendungen']]];");

        $keys = $this->app()->container()->get(\Preflow\I18n\DerivedKeys::class)->all();
        $this->assertSame('Apps', $keys['site.types.app.label']['value']);
        $this->assertArrayHasKey('site.options.app.kind.web', $keys);
        [$code, $out] = $this->check();
        $this->assertStringNotContainsString('model labels', $out);
        $this->assertSame(0, $code, $out);
    }

    #[RunInSeparateProcess]
    public function test_translations_check_lists_an_extensions_model_labels_missing_in_a_delivered_language(): void
    {
        $this->site('Apps');
        // An extension whose package declares the admin group "acme" (en and de only for its labels; fr is delivered by Folio).
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/things', 'install-path' => '../acme/things', 'extra' => ['preflow' => ['translations' => ['acme' => ['path' => 'lang', 'scope' => 'admin']]]]],
        ]]));
        $this->write('vendor/acme/things/lang/en/acme.php', "<?php return ['types' => ['thing' => ['label' => 'Things', 'singular' => 'Thing']], 'fields' => ['thing' => ['size' => ['label' => 'Size']]], 'sections' => ['tools' => 'Tools']];");
        $this->write('vendor/acme/things/lang/de/acme.php', "<?php return ['types' => ['thing' => ['label' => 'Dinge', 'singular' => 'Ding']], 'fields' => ['thing' => ['size' => ['label' => 'Größe']]], 'sections' => ['tools' => 'Werkzeuge']];");
        $this->write('app/Folio/Acme/Extension.php', "<?php\nnamespace App\\Folio\\Acme;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioExtension(name: 'acme', label: 'Acme', version: '1.0.0', translations: 'acme')]\nfinal class Extension extends \\Preflow\\Folio\\Extension\\Extension {}\n");
        require_once $this->dir . '/app/Folio/Acme/Extension.php'; // the test runner's autoloader doesn't know the site's app/
        $this->write('app/Folio/Acme/models/thing.json', (string) json_encode([
            'key' => 'thing', 'table' => 'thing', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Things', 'section' => 'tools',
            'fields' => ['size' => ['type' => 'string', 'label' => ['en' => 'Size', 'fr' => 'Taille']], 'note' => ['type' => 'string']],
        ]));
        $this->write('config/folio/extensions.json', '{"enabled": {"acme": {"disabled_parts": []}}}');

        [$code, $out] = $this->check();

        $this->assertSame(1, $code, $out);
        $this->assertStringContainsString("Missing in de (model labels of acme, 1):\n  acme.fields.thing.note.label\n", $out);
        $this->assertStringContainsString("Missing in fr (model labels of acme, 4):\n  acme.fields.thing.note.label\n  acme.sections.tools\n  acme.types.thing.label\n  acme.types.thing.singular\n", $out);
        // The model file's map has French for size (the group's own file check still lists its English-only key).
        $this->assertStringNotContainsString('acme.fields.thing.size.label', substr($out, (int) strpos($out, 'Missing in fr (model labels')));
        $this->assertStringNotContainsString('site.', $out);
    }
}
