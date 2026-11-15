<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Http\LocaleUrls;
use Preflow\I18n\TranslationExtensionProvider;
use Preflow\I18n\Translator;
use Preflow\View\TemplateFunctionDefinition;

final class TranslationExtensionProviderTest extends TestCase
{
    private TranslationExtensionProvider $provider;

    protected function setUp(): void
    {
        $langDir = __DIR__ . '/fixtures/lang';
        if (!is_dir($langDir . '/en')) {
            mkdir($langDir . '/en', 0755, true);
            file_put_contents($langDir . '/en/app.php', "<?php return ['hello' => 'Hello'];");
        }
        $translator = new Translator($langDir, 'en', 'en');
        $this->provider = new TranslationExtensionProvider($translator);
    }

    public function test_provides_t_and_tc_functions(): void
    {
        $functions = $this->provider->getTemplateFunctions();

        $this->assertSame(['t', 'tc', 'current_locale', 'available_locales', 'locale_url', 'locale_switch_url'], array_map(fn ($f) => $f->name, $functions));
        $this->assertSame('t', $functions[0]->name);
        $this->assertSame('tc', $functions[1]->name);
        $this->assertTrue($functions[0]->isSafe);
        $this->assertTrue($functions[1]->isSafe);
    }

    public function test_t_function_translates(): void
    {
        $functions = $this->provider->getTemplateFunctions();
        $t = $functions[0]->callable;

        $this->assertSame('Hello', $t('app.hello'));
    }

    public function test_globals_are_empty(): void
    {
        $this->assertSame([], $this->provider->getTemplateGlobals());
    }

    public function test_t_escapes_params(): void
    {
        $t = $this->function('t');

        $this->assertSame('Hi &lt;script&gt;x&lt;/script&gt;', $t('app.greet', ['name' => '<script>x</script>']));
    }

    public function test_locale_url(): void
    {
        $translator = new Translator(__DIR__ . '/fixtures/lang', 'en', 'en');
        $provider = new TranslationExtensionProvider($translator, ['en', 'de'], 'en');
        $url = null;
        foreach ($provider->getTemplateFunctions() as $fn) {
            if ($fn->name === 'locale_url') {
                $url = $fn->callable;
            }
        }

        $this->assertNotNull($url);
        $this->assertSame('/about', $url('/about'));
        $this->assertSame('/de/about', $url('/about', 'de'));
        $this->assertSame('/de', $url('/', 'de'));
        $this->assertSame('/', $url('/', 'en'));
    }

    private function fn(TranslationExtensionProvider $provider, string $name): callable
    {
        foreach ($provider->getTemplateFunctions() as $fn) {
            if ($fn->name === $name) {
                return $fn->callable;
            }
        }
        $this->fail("no {$name}");
    }

    private function providerWith(LocaleUrls $urls): TranslationExtensionProvider
    {
        return new TranslationExtensionProvider(new Translator(__DIR__ . '/fixtures/lang', 'en', 'en'), ['en', 'de'], 'en', $urls);
    }

    public function test_locale_url_prefixes_default_with_prefix_default(): void
    {
        $url = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en', true, true)), 'locale_url');

        $this->assertSame('/en/about', $url('/about', 'en'));
        $this->assertSame('/de/about', $url('/about', 'de'));
    }

    public function test_locale_url_root_is_bare_prefix(): void
    {
        $url = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en', true, true)), 'locale_url');

        $this->assertSame('/en', $url('/', 'en'));
        $this->assertSame('/de', $url('/', 'de'));
    }

    public function test_locale_url_unchanged_without_locale_urls(): void
    {
        $url = $this->fn(new TranslationExtensionProvider(new Translator(__DIR__ . '/fixtures/lang', 'en', 'en'), ['en', 'de'], 'en'), 'locale_url');

        $this->assertSame('/about', $url('/about', 'en'));
        $this->assertSame('/de/about', $url('/about', 'de'));
    }

    public function test_locale_url_with_default_locale_urls_keeps_todays_output(): void
    {
        $url = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en')), 'locale_url');
        $this->assertSame('/about', $url('/about', 'en'));
        $this->assertSame('/de/about', $url('/about', 'de'));
        $this->assertSame('/fr/x', $url('/x', 'fr'));

        $none = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en', false)), 'locale_url');
        $this->assertSame('/de/x', $none('/x', 'de'));
    }

    public function test_locale_switch_url_goes_through_the_switch_endpoint(): void
    {
        $switch = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en', true, false, true)), 'locale_switch_url');

        $this->assertSame('/--locale/de?to=%2Fde%2Ffaq', $switch('/faq', 'de'));
    }

    public function test_locale_switch_url_encodes_a_non_ascii_target(): void
    {
        $switch = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en', true, false, true)), 'locale_switch_url');

        $this->assertSame('/--locale/de?to=%2Fde%2F%25C3%25BCber-uns', $switch('/über-uns', 'de'));
    }

    public function test_locale_switch_url_is_locale_url_without_redirect_bare(): void
    {
        $switch = $this->fn($this->providerWith(new LocaleUrls(['en', 'de'], 'en')), 'locale_switch_url');

        $this->assertSame('/de/faq', $switch('/faq', 'de'));
    }

    public function test_current_and_available_locales(): void
    {
        $translator = new Translator(__DIR__ . '/fixtures/lang', 'de', 'en');
        $provider = new TranslationExtensionProvider($translator, ['en', 'de'], 'en');
        $fns = [];
        foreach ($provider->getTemplateFunctions() as $fn) {
            $fns[$fn->name] = $fn->callable;
        }

        $this->assertSame('de', $fns['current_locale']());
        $this->assertSame(['en', 'de'], $fns['available_locales']());
    }

    private function function(string $name): callable
    {
        foreach ($this->provider->getTemplateFunctions() as $fn) {
            if ($fn->name === $name) {
                return $fn->callable;
            }
        }
        $this->fail("No template function {$name}");
    }
}
