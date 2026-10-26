<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Labels;
use Preflow\I18n\Tests\Support\TempLang;
use Preflow\I18n\TranslationOverrides;
use Preflow\I18n\Translator;

/** Display texts given as a string or as a map per language (model files). */
final class LabelsTest extends TestCase
{
    use TempLang;

    protected function tearDown(): void
    {
        $this->removeTmp();
    }

    public function test_pick(): void
    {
        $this->assertSame('Apps', Labels::pick('Apps', 'fr', 'de'));
        $this->assertSame('Anwendungen', Labels::pick(['en' => 'Apps', 'de' => 'Anwendungen'], 'fr', 'de'));
        $this->assertSame('Apps', Labels::pick(['en' => 'Apps'], 'fr', 'de'));
        $this->assertSame('Applications', Labels::pick(['en' => 'Apps', 'fr' => 'Applications'], 'fr', 'de'));
        $this->assertSame('Aplicaciones', Labels::pick(['es' => 'Aplicaciones', 'it' => 'Applicazioni'], 'fr', 'de'), 'else the first');
        $this->assertNull(Labels::pick(null, 'fr', 'de'));
        $this->assertNull(Labels::pick([], 'fr', 'de'));
    }

    public function test_resolve_prefers_the_translator_key(): void
    {
        $this->langFile('lang', 'fr', 'site', ['types' => ['app' => ['label' => 'Applis']]]);
        $t = new Translator($this->tmp() . '/lang', 'fr', 'de');

        $this->assertSame('Applis', Labels::resolve($t, 'site.types.app.label', ['en' => 'Apps', 'fr' => 'Applications']));
        $this->assertSame('Anwendungen', Labels::resolve($t, 'site.types.page.label', ['en' => 'Pages', 'de' => 'Anwendungen']));
        $this->assertSame('Apps', Labels::resolve($t, 'site.types.x.label', 'Apps'));
        $this->assertNull(Labels::resolve($t, 'site.types.y.label', null));
    }

    public function test_resolve_uses_an_admin_override(): void
    {
        $t = new Translator($this->tmp() . '/lang', 'fr', 'de');
        $t->setScopes(['site' => 'admin']);
        $t->setOverrides(new class implements TranslationOverrides {
            public function get(string $locale, string $key): ?string
            {
                return $locale === 'fr' && $key === 'site.types.app.label' ? 'Mes applis' : null;
            }
        });
        $t->useOverrides(true);

        $this->assertSame('Mes applis', Labels::resolve($t, 'site.types.app.label', 'Apps'));
    }

    public function test_resolve_map_beats_a_key_in_another_locale(): void
    {
        $this->langFile('lang', 'en', 'site', ['types' => ['app' => ['label' => 'Apps (en file)']]]);
        $t = new Translator($this->tmp() . '/lang', 'fr', 'de');

        $this->assertSame('Applications', Labels::resolve($t, 'site.types.app.label', ['fr' => 'Applications']));
        $this->assertSame('Apps (en file)', Labels::resolve($t, 'site.types.app.label', null), 'no text given: the key through the fallback chain');
    }
}
