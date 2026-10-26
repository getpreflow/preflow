<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\NoTranslationOverrides;
use Preflow\I18n\Tests\Support\TempLang;
use Preflow\I18n\TranslationOverrides;
use Preflow\I18n\Translator;

/** Layers (override > site file > package file), scopes, the locale fallback chain. */
final class TranslatorLayersTest extends TestCase
{
    use TempLang;

    protected function tearDown(): void
    {
        $this->removeTmp();
    }

    /** @param array<string, array<string, string>> $map locale => key => text */
    private static function overrides(array $map): TranslationOverrides
    {
        return new class ($map) implements TranslationOverrides {
            /** @param array<string, array<string, string>> $map */
            public function __construct(private readonly array $map) {}

            public function get(string $locale, string $key): ?string
            {
                return $this->map[$locale][$key] ?? null;
            }
        };
    }

    private function translator(string $locale = 'en', string $fallback = 'en'): Translator
    {
        $site = $this->tmp() . '/lang';
        $pkg = $this->tmp() . '/pkg/lang';
        $this->langFile('pkg/lang', 'en', 'adm', ['a' => 'Package A', 'b' => 'Package B', 'c' => 'Package C']);
        $this->langFile('pkg/lang', 'en', 'pub', ['a' => 'Public package A']);
        $this->langFile('lang', 'en', 'adm', ['b' => 'Site B', 'c' => 'Site C']);
        $this->langFile('lang', 'en', 'pub', ['a' => 'Public site A']);

        $t = new Translator($site, $locale, $fallback);
        $t->addDefaults('adm', $pkg);
        $t->addDefaults('pub', $pkg);
        $t->setScopes(['adm' => 'admin', 'pub' => 'public']);
        $t->setOverrides(self::overrides(['en' => ['adm.c' => 'Override C', 'pub.a' => 'Public override']]));

        return $t;
    }

    public function test_admin_group_with_overrides_on(): void
    {
        $t = $this->translator();
        $t->useOverrides(true);

        $this->assertSame('Override C', $t->get('adm.c'), 'override first');
        $this->assertSame('Site B', $t->get('adm.b'), 'then the site file');
        $this->assertSame('Package A', $t->get('adm.a'), 'then the package');
    }

    public function test_public_group_ignores_overrides(): void
    {
        $t = $this->translator();
        $t->useOverrides(true);

        $this->assertSame('Public site A', $t->get('pub.a'));
    }

    public function test_overrides_are_off_by_default_and_when_turned_off(): void
    {
        $t = $this->translator();
        $this->assertSame('Site C', $t->get('adm.c'), 'off by default');
        $this->assertFalse($t->usesOverrides());

        $t->useOverrides(true);
        $this->assertTrue($t->usesOverrides());
        $t->useOverrides(false);
        $this->assertFalse($t->usesOverrides());
        $this->assertSame('Site C', $t->get('adm.c'));
        $this->assertSame('Public site A', $t->get('pub.a'));
    }

    public function test_the_overrides_layer_can_be_read_back(): void
    {
        $t = new Translator($this->tmp() . '/lang', 'en', 'en');
        $this->assertInstanceOf(\Preflow\I18n\NoTranslationOverrides::class, $t->overrides());
        $own = new \Preflow\I18n\NoTranslationOverrides();
        $t->setOverrides($own);
        $this->assertSame($own, $t->overrides());
    }

    public function test_no_overrides_returns_null(): void
    {
        $this->assertNull((new NoTranslationOverrides())->get('en', 'adm.c'));
    }

    public function test_fallback_chain_locale_then_site_default_then_en(): void
    {
        $this->langFile('lang', 'en', 'g', ['only_en' => 'English only']);
        $this->langFile('lang', 'de', 'g', ['only_de' => 'Nur Deutsch']);
        $t = new Translator($this->tmp() . '/lang', 'fr', 'de');

        $this->assertSame('English only', $t->get('g.only_en'));
        $this->assertSame('Nur Deutsch', $t->get('g.only_de'));
        $this->assertSame('g.nowhere', $t->get('g.nowhere'));
        $this->assertSame('English only', $t->choice('g.only_en', 2));
    }

    public function test_overrides_follow_the_fallback_chain(): void
    {
        $t = $this->translator('fr', 'de');
        $t->useOverrides(true);

        $this->assertSame('Override C', $t->get('adm.c'), 'no fr or de text: the en override');
    }

    public function test_parameters_and_plurals(): void
    {
        $this->langFile('lang', 'de', 'g', ['hello' => 'Hallo :name', 'files' => ':count Datei|:count Dateien']);
        $this->langFile('lang', 'fr', 'g', ['hello' => 'Bonjour :name', 'files' => ':count fichier|:count fichiers']);
        $t = new Translator($this->tmp() . '/lang', 'de', 'de');

        $this->assertSame('Hallo Ana', $t->get('g.hello', ['name' => 'Ana']));
        $this->assertSame('1 Datei', $t->choice('g.files', 1));
        $this->assertSame('2 Dateien', $t->choice('g.files', 2));

        $t->setLocale('fr');
        $this->assertSame('1 fichier', $t->choice('g.files', 1));
        $this->assertSame('2 fichiers', $t->choice('g.files', 2));
    }

    public function test_has_checks_one_locale_with_all_layers(): void
    {
        $t = $this->translator();
        $this->langFile('lang', 'de', 'adm', ['d' => 'Site D']);
        $this->assertTrue($t->has('adm.a'));
        $this->assertTrue($t->has('adm.b', 'en'));
        $this->assertFalse($t->has('adm.a', 'de'), 'no fallback');
        $this->assertTrue($t->has('adm.d', 'de'));
        $this->assertFalse($t->has('adm.nope'));
        $this->assertFalse($t->has('adm'), 'a whole group is not a key');

        $t->setOverrides(self::overrides(['de' => ['adm.x' => 'Ü']]));
        $this->assertFalse($t->has('adm.x', 'de'), 'overrides off');
        $t->useOverrides(true);
        $this->assertTrue($t->has('adm.x', 'de'));
    }

    public function test_groups_lists_package_and_site_groups(): void
    {
        $t = $this->translator();
        $this->langFile('lang', 'de', 'site', ['x' => 'X']);

        $this->assertSame(['adm', 'pub', 'site'], $t->groups());
    }

    public function test_locales_with_files_per_group_or_overall(): void
    {
        $t = $this->translator();
        $this->langFile('pkg/lang', 'fr', 'adm', ['a' => 'A fr']);
        $this->langFile('lang', 'de', 'site', ['x' => 'X']);

        $this->assertSame(['en', 'fr'], $t->locales('adm'));
        $this->assertSame(['de'], $t->locales('site'));
        $this->assertSame([], $t->locales('nope'));
        $this->assertSame(['de', 'en', 'fr'], $t->locales());
    }

    public function test_site_locales_are_the_languages_of_the_sites_own_files(): void
    {
        $t = $this->translator();
        $this->langFile('pkg/lang', 'fr', 'adm', ['a' => 'A fr']);
        $this->langFile('lang', 'de', 'site', ['x' => 'X']);

        $this->assertNotContains('fr', $t->siteLocales(), 'a package language is not a site language');
        $this->assertContains('de', $t->siteLocales());
    }

    public function test_entries_are_flattened_full_keys_of_one_locale(): void
    {
        $t = $this->translator();
        $this->langFile('lang', 'en', 'nest', ['a' => ['b' => 'AB', 'c' => ['d' => 'ACD']], 'e' => 'E']);
        $t->useOverrides(true);

        $this->assertSame(['adm.a' => 'Package A', 'adm.b' => 'Site B', 'adm.c' => 'Site C'], $t->entries('adm', 'en'), 'site over package, no overrides');
        $this->assertSame(['nest.a.b' => 'AB', 'nest.a.c.d' => 'ACD', 'nest.e' => 'E'], $t->entries('nest', 'en'));
        $this->assertSame([], $t->entries('adm', 'fr'), 'no fallback');
    }

    public function test_add_defaults_if_missing_only_registers_undeclared_groups(): void
    {
        $t = $this->translator();
        $this->langFile('other/lang', 'en', 'adm', ['a' => 'Other A']);
        $this->langFile('other/lang', 'en', 'late', ['a' => 'Late A']);

        $t->addDefaultsIfMissing('adm', $this->tmp() . '/other/lang', 'public');
        $t->addDefaultsIfMissing('late', $this->tmp() . '/other/lang', 'admin');

        $this->assertSame('Package A', $t->get('adm.a'), 'already declared: no-op');
        $this->assertSame('admin', $t->scope('adm'), 'scope untouched');
        $this->assertSame('Late A', $t->get('late.a'));
        $this->assertSame('admin', $t->scope('late'));
    }

    public function test_overridable_prefixes_of_a_group_without_scope(): void
    {
        $t = $this->translator();
        $this->langFile('lang', 'en', 'site', ['types' => ['app' => ['label' => 'Apps']], 'greeting' => 'Hi']);
        $t->setOverrides(self::overrides(['en' => [
            'site.types.app.label' => 'Programs', 'site.greeting' => 'Yo', 'pub.a' => 'Public override',
        ]]));
        $t->overridable('site.types.');
        $t->overridable('pub.');

        $this->assertSame('Apps', $t->get('site.types.app.label'), 'overrides off');
        $t->useOverrides(true);
        $this->assertSame('Programs', $t->get('site.types.app.label'), 'under an overridable prefix');
        $this->assertTrue($t->has('site.types.app.label'));
        $this->assertSame('Hi', $t->get('site.greeting'), 'other keys of the group keep the files');
        $this->assertSame('Public site A', $t->get('pub.a'), 'a public group never takes overrides');
        $this->assertTrue($t->isOverridable('site.types.app.label'));
        $this->assertTrue($t->isOverridable('adm.a'));
        $this->assertFalse($t->isOverridable('site.greeting'));
        $this->assertFalse($t->isOverridable('pub.a'));
    }

    public function test_package_and_site_entries_one_layer_each(): void
    {
        $t = $this->translator();

        $this->assertSame(['adm.a' => 'Package A', 'adm.b' => 'Package B', 'adm.c' => 'Package C'], $t->packageEntries('adm', 'en'));
        $this->assertSame(['adm.b' => 'Site B', 'adm.c' => 'Site C'], $t->siteEntries('adm', 'en'));
        $this->assertSame([], $t->packageEntries('adm', 'fr'));
        $this->assertSame([], $t->siteEntries('nope', 'en'));
    }

    public function test_fallback_locale_is_readable(): void
    {
        $this->assertSame('de', (new Translator($this->tmp(), 'fr', 'de'))->getFallbackLocale());
    }

    private function reviewMail(string $locale = 'fr', string $fallback = 'fr'): Translator
    {
        $this->langFile('lang', 'fr', 'review-mail', ['approved' => ['subject' => 'En ligne']]);
        $t = new Translator($this->tmp() . '/lang', $locale, $fallback);
        $t->setOverrides(self::overrides([
            'fr' => ['review-mail.approved.subject' => 'Votre objet est en ligne'],
            'de' => ['review-mail.approved.subject' => 'Ihr Objekt ist online'],
        ]));

        return $t;
    }

    public function test_an_always_overridden_group_takes_overrides_with_overrides_off(): void
    {
        $t = $this->reviewMail();
        $t->useOverrides(false);
        $this->assertSame('En ligne', $t->get('review-mail.approved.subject', [], 'fr'));

        $t->alwaysOverride('review-mail');
        $this->assertSame('Votre objet est en ligne', $t->get('review-mail.approved.subject', [], 'fr'));
    }

    public function test_an_always_overridden_group_uses_the_asked_locale_not_the_current_one(): void
    {
        $t = $this->reviewMail('de', 'de');
        $t->alwaysOverride('review-mail');

        $this->assertSame('Ihr Objekt ist online', $t->get('review-mail.approved.subject'));
        $this->assertSame('Votre objet est en ligne', $t->get('review-mail.approved.subject', [], 'fr'));
    }

    public function test_a_locale_file_wins_over_a_fallback_locale_override(): void
    {
        $key = 'review-mail.approved.subject';
        $this->langFile('lang', 'it', 'review-mail', ['approved' => ['subject' => 'Online']]);
        $t = $this->reviewMail('it', 'de');
        $t->alwaysOverride('review-mail');
        $this->assertSame('Online', $t->get($key, [], 'it'));

        unlink($this->tmp() . '/lang/it/review-mail.php');
        $t = new Translator($this->tmp() . '/lang', 'it', 'de');
        $t->setOverrides(self::overrides(['de' => [$key => 'Ihr Objekt ist online']]));
        $t->alwaysOverride('review-mail');
        $this->assertSame('Ihr Objekt ist online', $t->get($key, [], 'it'));
    }

    public function test_parameters_in_an_always_overridden_text_are_replaced(): void
    {
        $t = new Translator($this->tmp() . '/lang', 'de', 'de');
        $t->setOverrides(self::overrides(['de' => ['review-mail.hello' => 'Hallo :name']]));
        $t->alwaysOverride('review-mail');

        $this->assertSame('Hallo Ada', $t->get('review-mail.hello', ['name' => 'Ada']));
    }

    public function test_an_admin_group_cannot_be_always_overridden(): void
    {
        $t = new Translator($this->tmp() . '/lang', 'en', 'en');
        $t->setScopes(['folio' => 'admin']);
        $t->setOverrides(self::overrides(['en' => ['folio.x' => 'Over']]));

        foreach (['folio', ''] as $group) {
            try {
                $t->alwaysOverride($group);
                $this->fail('expected InvalidArgumentException for "' . $group . '"');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('folio.x', $t->get('folio.x'), 'overrides off: still needs useOverrides(true)');
        $t->useOverrides(true);
        $this->assertSame('Over', $t->get('folio.x'));
    }

    public function test_is_overridable_and_the_list(): void
    {
        $t = new Translator($this->tmp() . '/lang', 'en', 'en');
        $t->setScopes(['members' => 'public']);
        $t->alwaysOverride('review-mail');
        $t->alwaysOverride('review-mail');

        $this->assertTrue($t->isOverridable('review-mail.x'));
        $this->assertSame(['review-mail'], $t->alwaysOverridden());
        $this->assertFalse($t->isOverridable('members.x'));

        $t->alwaysOverride('a-group');
        $this->assertSame(['a-group', 'review-mail'], $t->alwaysOverridden());
    }

    public function test_other_keys_never_ask_the_override_layer(): void
    {
        $counter = new class implements TranslationOverrides {
            public int $calls = 0;

            public function get(string $locale, string $key): ?string
            {
                $this->calls++;

                return null;
            }
        };
        $t = new Translator($this->tmp() . '/lang', 'en', 'en');
        $t->setScopes(['members' => 'public']);
        $t->setOverrides($counter);
        $t->useOverrides(false);
        $t->alwaysOverride('review-mail');

        $t->get('members.x');
        $this->assertSame(0, $counter->calls);
        $t->get('review-mail.x');
        $this->assertSame(1, $counter->calls);
    }
}
