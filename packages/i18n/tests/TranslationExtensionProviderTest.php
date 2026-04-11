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

        $this->assertCount(2, $functions);
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
}
