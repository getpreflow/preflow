<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests;

use PHPUnit\Framework\TestCase;
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

        $this->assertSame(['t', 'tc', 'current_locale', 'available_locales', 'locale_url'], array_map(fn ($f) => $f->name, $functions));
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
