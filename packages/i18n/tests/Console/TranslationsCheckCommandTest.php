<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\I18n\Console\TranslationsCheckCommand;
use Preflow\I18n\Tests\Support\TempLang;

/** preflow translations:check — the release gate for "no raw keys". */
final class TranslationsCheckCommandTest extends TestCase
{
    use TempLang;

    protected function setUp(): void
    {
        $this->file('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        $this->file('app/pages/index.twig', 'HOME');
        $this->file('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/g', 'install-path' => '../acme/g', 'extra' => ['preflow' => ['translations' => ['g' => 'lang']]]],
        ]]));
        $this->langFile('vendor/acme/g/lang', 'en', 'g', ['a' => 'A', 'nest' => ['b' => 'B']]);
        $this->langFile('vendor/acme/g/lang', 'de', 'g', ['a' => 'A de', 'nest' => ['b' => 'B de']]);
        $this->file('vendor/acme/g/templates/page.twig', "{{ t('g.a') }} {{ t(\"g.nest.b\", {x: 1}) }}");
    }

    protected function tearDown(): void
    {
        $this->removeTmp();
    }

    /** @return array{0: int, 1: string} */
    private function check(array $args = []): array
    {
        $dir = $this->tmp();
        ob_start();
        $code = (new TranslationsCheckCommand(static function () use ($dir): Application {
            $app = Application::create($dir);
            $app->boot();

            return $app;
        }))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_a_complete_fixture_exits_0(): void
    {
        $this->langFile('vendor/acme/g/lang', 'fr', 'g', ['a' => 'A fr', 'nest' => ['b' => 'B fr']]);

        [$code, $out] = $this->check();

        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('g.a', $out);
    }

    public function test_missing_keys_and_unknown_used_keys_exit_1(): void
    {
        $this->langFile('vendor/acme/g/lang', 'fr', 'g', ['a' => 'A fr']);
        $this->file('vendor/acme/g/templates/other.twig', "{% if x %}{{ t('g.nope') }}{% endif %}");
        $this->file('app/pages/about.twig', "{{ t('site.missing.title') }}");

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/\bfr\b.*g\.nest\.b/s', $out);
        $this->assertStringNotContainsString("g.a\n", $out);
        $this->assertStringContainsString('g.nope', $out);
        $this->assertStringContainsString('other.twig', $out);
        $this->assertStringContainsString('site.missing.title', $out);
        $this->assertStringContainsString('about.twig', $out);
    }

    public function test_a_package_group_without_a_delivered_language_exits_1(): void
    {
        $this->file('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'acme/g', 'install-path' => '../acme/g', 'extra' => ['preflow' => ['translations' => ['g' => 'lang']]]],
            ['name' => 'acme/h', 'install-path' => '../acme/h', 'extra' => ['preflow' => ['translations' => ['h' => 'lang']]]],
        ]]));
        // g ships en and de (setUp), h en, de and fr: g lacks fr entirely.
        foreach (['en', 'de', 'fr'] as $locale) {
            $this->langFile('vendor/acme/h/lang', $locale, 'h', ['x' => "X {$locale}"]);
        }
        $this->langFile('lang', 'en', 'site', ['only' => 'English site text']);

        [$code, $out] = $this->check();

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/\bfr\b.*\(g\b.*g\.a.*g\.nest\.b/s', $out);
        $this->assertStringNotContainsString('site.only', $out, 'a site-only group: only languages it has files for');
    }

    public function test_site_files_complete_a_package_group(): void
    {
        $this->langFile('vendor/acme/g/lang', 'fr', 'g', ['a' => 'A fr']);
        $this->langFile('lang', 'fr', 'g', ['nest' => ['b' => 'B site fr']]);

        [$code, $out] = $this->check();

        $this->assertSame(0, $code, $out);
    }

    public function test_group_option_limits_the_check(): void
    {
        $this->langFile('vendor/acme/g/lang', 'fr', 'g', ['a' => 'A fr', 'nest' => ['b' => 'B fr']]);
        $this->langFile('lang', 'en', 'site', ['x' => 'X', 'y' => 'Y']);
        $this->langFile('lang', 'de', 'site', ['x' => 'X de']);

        $this->assertSame(1, $this->check()[0]);
        [$code, $out] = $this->check(['--group=g']);
        $this->assertSame(0, $code, $out);
    }

    public function test_metadata(): void
    {
        $command = new TranslationsCheckCommand();
        $this->assertSame('translations:check', $command->getName());
        $this->assertNotSame('', $command->getDescription());
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);
        $this->assertContains(TranslationsCheckCommand::class, $composer['extra']['preflow']['commands']);
    }
}
