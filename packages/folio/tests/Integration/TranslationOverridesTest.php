<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Http\FolioPermissionMiddleware;
use Preflow\Folio\Tests\Support\StaffScaffold;
use Preflow\Folio\Transfer\ContentSet;
use Preflow\Folio\Transfer\Exporter;
use Preflow\Folio\Transfer\Importer;
use Preflow\Folio\Translations\StoredOverrides;
use Preflow\Folio\Users\UserPreferences;
use Preflow\I18n\Translator;
use Psr\Http\Message\ResponseInterface;

/**
 * Settings → Translations: admins change interface texts per language. An override wins on
 * admin requests only, for admin groups and the site's model labels; public pages never
 * change. Plain text only; configuration values and texts with markup are not offered. The
 * overrides are compiled per locale (a cache on Settings → Cache) and travel with an export.
 */
final class TranslationOverridesTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    private function overrides(): StoredOverrides
    {
        return $this->flow->container()->get(StoredOverrides::class);
    }

    private function prefs(): UserPreferences
    {
        return $this->flow->container()->get(UserPreferences::class);
    }

    private function signIn(string $email): void
    {
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => $email, 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    /** Ada reads the admin in German, Bob in French. */
    private function people(): void
    {
        $ada = $this->makeStaff();
        $bob = $this->makeStaff('bob@site.test', data: ['name' => 'Bob']);
        $this->prefs()->setLocale((string) $ada->getId(), 'de');
        $this->prefs()->setLocale((string) $bob->getId(), 'fr');
    }

    private function compiled(string $locale): string
    {
        return $this->dir . '/storage/cache/folio-translations-' . $locale . '.php';
    }

    #[RunInSeparateProcess]
    public function test_an_override_changes_the_admin_in_that_language_only(): void
    {
        $this->people();
        $this->signIn('ada@site.test');

        $screen = $this->html('/folio/translations?group=folio&locale=de&q=common.save');
        $this->assertStringContainsString('folio.common.save', $screen);
        $this->assertStringContainsString('Speichern', $screen, 'the delivered text');

        $response = $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => 'Sichern', 'group' => 'folio', 'q' => 'common.save']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/folio/translations?group=folio&locale=de&q=common.save', $response->getHeaderLine('Location'), 'back to the same view');
        $this->assertSame(['folio.common.save' => 'Sichern'], $this->overrides()->all('de'));

        $account = $this->html('/folio/_account');
        $this->assertStringContainsString('>Sichern</button>', $account);
        $this->assertStringNotContainsString('>Speichern</button>', $account);
        $this->assertMatchesRegularExpression('~value="Sichern"~', $this->html('/folio/translations?group=folio&locale=de&q=common.save'), 'the field shows the override');

        $this->useSession([]);
        $this->signIn('bob@site.test');
        $account = $this->html('/folio/_account');
        $this->assertStringContainsString('>Enregistrer</button>', $account, 'French is unchanged');
        $this->assertStringNotContainsString('Sichern', $account);
    }

    #[RunInSeparateProcess]
    public function test_reset_brings_back_the_delivered_text(): void
    {
        $this->people();
        $this->signIn('ada@site.test');
        $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => 'Sichern']);
        $this->assertStringContainsString('>Sichern</button>', $this->html('/folio/_account'));

        $response = $this->post('/folio/translations/reset', ['locale' => 'de', 'key' => 'folio.common.save']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->overrides()->all('de'));
        $this->assertStringContainsString('>Speichern</button>', $this->html('/folio/_account'));

        // Saving an empty text is a reset too.
        $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => 'Sichern']);
        $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => '']);
        $this->assertSame([], $this->overrides()->all('de'));
    }

    #[RunInSeparateProcess]
    public function test_markup_and_configuration_are_refused_and_not_listed(): void
    {
        $this->people();
        $this->signIn('ada@site.test');

        $response = $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => 'Sichern <b>jetzt</b>']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->overrides()->all('de'), 'nothing stored');

        $screen = $this->html('/folio/translations?group=folio&locale=de');
        $this->assertStringNotContainsString('folio.extensions.changes', $screen, 'its English text has <code>');
        $this->assertStringNotContainsString('folio.format.', $screen, 'configuration, not texts');
        $this->assertSame('', $this->listed('/folio/translations?group=folio&locale=de&q=extensions.changes'));
        $this->assertSame('', $this->listed('/folio/translations?group=folio&locale=de&q=format.date'));

        foreach (['folio.extensions.changes', 'folio.format.date', 'folio.format.thousands', 'folio.nothing.here', 'members.form.name', ''] as $key) {
            $this->assertSame(422, $this->post('/folio/translations', ['locale' => 'de', 'key' => $key, 'text' => 'x'])->getStatusCode(), $key);
        }
        $this->assertSame(422, $this->post('/folio/translations', ['locale' => 'xx', 'key' => 'folio.common.save', 'text' => 'x'])->getStatusCode(), 'a language that is not offered');
        $this->assertSame([], $this->overrides()->all('de'));
        $this->assertSame([], $this->overrides()->all('xx'));
    }

    /** The keys of the listed rows, comma-separated. */
    private function listed(string $uri): string
    {
        preg_match_all('~<input type="hidden" name="key" value="([^"]+)"~', $this->html($uri), $m);

        return implode(',', $m[1]);
    }

    #[RunInSeparateProcess]
    public function test_the_screen_lists_admin_groups_and_the_sites_model_labels_50_a_page(): void
    {
        $this->people();
        $this->write('lang/de/site.php', "<?php\nreturn ['types' => ['page' => ['label' => 'Webseiten']], 'greeting' => 'Hallo'];\n");
        $this->flow = $this->app();
        $this->signIn('ada@site.test');

        $page = $this->html('/folio/translations?group=folio&locale=de');
        $this->assertSame(50, substr_count($page, '<input type="hidden" name="key"'), '50 rows a page');
        $this->assertStringContainsString('page=2', $page);
        $this->assertMatchesRegularExpression('~<option value="site"[^>]*>~', $page, 'the site group is offered');
        $this->assertDoesNotMatchRegularExpression('~<option value="members"~', $page);

        $site = $this->html('/folio/translations?group=site&locale=de');
        $keys = explode(',', $this->listed('/folio/translations?group=site&locale=de'));
        $this->assertContains('site.types.page.label', $keys);
        $this->assertContains('site.fields.page.title.label', $keys);
        $this->assertNotContains('site.greeting', $keys, 'only the model labels of the site group');
        $this->assertStringContainsString('Pages', $site, "the model file's text");
        $this->assertStringContainsString('Webseiten', $site, "the site file's text, which differs");

        $this->assertSame(302, $this->post('/folio/translations', ['locale' => 'de', 'key' => 'site.types.page.label', 'text' => 'Seiten'])->getStatusCode());
        $this->assertSame(422, $this->post('/folio/translations', ['locale' => 'de', 'key' => 'site.greeting', 'text' => 'Moin'])->getStatusCode());
        $this->assertMatchesRegularExpression('~<a href="/folio/page"[^>]*>.*?Seiten~s', $this->html('/folio'), 'the sidebar takes the override');
    }

    #[RunInSeparateProcess]
    public function test_public_pages_never_see_an_override(): void
    {
        $this->write('app/pages/hello.twig', "{{ t('members.form.name') }}|{{ t('folio.common.save') }}|{{ t('site.types.page.label') }}");
        $this->flow = $this->app();
        $translator = $this->flow->container()->get(Translator::class);
        $translator->addDefaultsIfMissing('members', dirname(__DIR__, 3) . '/members/lang', 'public');
        $this->people();
        $this->signIn('ada@site.test');

        $this->assertStringNotContainsString('members.form.name', $this->html('/folio/translations?group=members&locale=en'), 'a public group is not listed');
        $this->assertSame(422, $this->post('/folio/translations', ['locale' => 'en', 'key' => 'members.form.name', 'text' => 'Changed'])->getStatusCode());

        $before = (string) $this->send('GET', '/hello')->getBody();
        $this->assertSame('Name|Save|site.types.page.label', $before);
        // Rows written directly, past the screen's checks.
        $this->overrides()->set('en', 'members.form.name', 'Changed name', null);
        $this->overrides()->set('en', 'folio.common.save', 'Changed save', null);
        $this->overrides()->set('en', 'site.types.page.label', 'Changed pages', null);
        $this->html('/folio');

        $this->assertSame($before, (string) $this->send('GET', '/hello')->getBody(), 'byte-identical');
        $this->assertFalse($translator->usesOverrides());
    }

    #[RunInSeparateProcess]
    public function test_the_compiled_cache_is_on_the_cache_screen_and_cleared_by_it_and_by_saves(): void
    {
        $this->people();
        $this->signIn('ada@site.test');
        $this->overrides()->forget();
        $this->assertFileDoesNotExist($this->compiled('de'));

        $this->html('/folio');
        $this->assertFileExists($this->compiled('de'), 'after the first admin request');

        $cache = $this->html('/folio/cache');
        $this->assertStringContainsString('<code>folio-translations</code>', $cache);
        $this->assertStringContainsString('Folio-Übersetzungen', $cache);
        $this->assertSame(302, $this->post('/folio/cache/clear', ['key' => 'folio-translations'])->getStatusCode());
        $this->assertFileDoesNotExist($this->compiled('de'));

        $this->html('/folio');
        $this->assertFileExists($this->compiled('de'));
        $this->post('/folio/translations', ['locale' => 'de', 'key' => 'folio.common.save', 'text' => 'Sichern']);
        $this->assertFileDoesNotExist($this->compiled('de'), 'every save');
        $this->assertStringContainsString('Sichern', $this->html('/folio/_account'));
        $this->assertFileExists($this->compiled('de'));
        $this->post('/folio/translations/reset', ['locale' => 'de', 'key' => 'folio.common.save']);
        $this->assertFileDoesNotExist($this->compiled('de'), 'and every reset');
    }

    #[RunInSeparateProcess]
    public function test_the_screen_needs_translations_edit_and_a_csrf_token(): void
    {
        foreach (['index', 'save', 'reset'] as $action) {
            $this->assertSame('translations.edit', FolioPermissionMiddleware::rule('Preflow\\Folio\\Http\\TranslationsController@' . $action));
        }
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => ['*'], 'editor' => ['folio.dashboard', 'page.*']]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $this->flow = $this->app();
        $this->makeStaff('eve@site.test', data: ['roles' => 'editor']);
        $this->makeStaff();

        $this->signIn('eve@site.test');
        $this->assertSame(403, $this->send('GET', '/folio/translations')->getStatusCode());
        $this->assertSame(403, $this->post('/folio/translations', ['locale' => 'en', 'key' => 'folio.common.save', 'text' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->post('/folio/translations/reset', ['locale' => 'en', 'key' => 'folio.common.save'])->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/translations"', $this->html('/folio'));
        $this->assertSame([], $this->overrides()->all('en'));

        $this->useSession([]);
        $this->signIn('ada@site.test');
        $this->assertSame(200, $this->send('GET', '/folio/translations')->getStatusCode());
        $this->assertStringContainsString('href="/folio/translations"', $this->html('/folio'), 'Settings → Translations');
        $noToken = $this->flow->handle((new Psr17Factory())->createServerRequest('POST', '/folio/translations', ['REMOTE_ADDR' => $this->remoteAddr])
            ->withParsedBody(['locale' => 'en', 'key' => 'folio.common.save', 'text' => 'x']));
        $this->assertSame(403, $noToken->getStatusCode(), 'CSRF');
        $this->assertSame([], $this->overrides()->all('en'));
    }

    #[RunInSeparateProcess]
    public function test_export_then_import_into_a_fresh_site_keeps_the_overrides(): void
    {
        $this->overrides()->set('de', 'folio.common.save', 'Sichern', 'ada');
        $this->overrides()->set('fr', 'site.types.page.label', 'Les pages', 'ada');
        $stream = fopen('php://temp', 'w+b');
        (new Exporter(ContentSet::fromApp($this->flow), $this->dm()))->export($stream);
        rewind($stream);
        $first = $this->dir;

        try {
            $this->staffSite();
            $this->people();
            $this->signIn('ada@site.test');
            $this->html('/folio'); // compiles the (empty) German overrides
            $this->assertSame([], $this->overrides()->all('de'));

            $importer = new Importer(ContentSet::fromApp($this->flow), $this->flow->container()->get(DataManager::class));
            $plan = $importer->read($stream);
            try {
                $importer->apply($plan);
            } finally {
                $plan->cleanup();
            }

            $this->assertSame(['folio.common.save' => 'Sichern'], $this->overrides()->all('de'));
            $this->assertSame(['site.types.page.label' => 'Les pages'], $this->overrides()->all('fr'));
            $this->assertStringContainsString('>Sichern</button>', $this->html('/folio/_account'), 'the compiled cache is renewed');
        } finally {
            $second = $this->dir;
            $this->dir = $first;
            $this->removeSite();
            $this->dir = $second;
        }
    }
}
