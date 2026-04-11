<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Preflow\I18n\Translator;
use Preflow\Twig\TranslationExtension;

final class TranslationExtensionTest extends TestCase
{
    private string $langDir;
    private Translator $translator;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->langDir = sys_get_temp_dir() . '/preflow_twig_i18n_' . uniqid();
        mkdir($this->langDir . '/en', 0755, true);
        mkdir($this->langDir . '/de', 0755, true);

        file_put_contents($this->langDir . '/en/app.php', '<?php return [
            "greeting" => "Hello, :name!",
        ];');

        file_put_contents($this->langDir . '/en/blog.php', '<?php return [
            "title" => "Blog",
            "post_count" => "{0} No posts|{1} One post|[2,*] :count posts",
        ];');

        file_put_contents($this->langDir . '/en/my-component.php', '<?php return [
            "label" => "Component Label",
        ];');

        file_put_contents($this->langDir . '/de/blog.php', '<?php return [
            "title" => "Blog",
        ];');

        $this->translator = new Translator($this->langDir, 'en', 'en');

        $this->twig = new Environment(new ArrayLoader([]), ['autoescape' => false]);
        $this->twig->addExtension(new TranslationExtension($this->translator));
    }

    protected function tearDown(): void
    {
        $this->deleteDir($this->langDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->deleteDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function render(string $template, array $context = []): string
    {
        return $this->twig->createTemplate($template)->render($context);
    }

    public function test_t_function_simple(): void
    {
        $result = $this->render("{{ t('blog.title') }}");

        $this->assertSame('Blog', $result);
    }

    public function test_t_function_with_params(): void
    {
        $result = $this->render("{{ t('app.greeting', { name: 'World' }) }}");

        $this->assertSame('Hello, World!', $result);
    }

    public function test_t_function_with_count(): void
    {
        $result = $this->render("{{ t('blog.post_count', { count: 5 }, 5) }}");

        $this->assertSame('5 posts', $result);
    }

    public function test_t_function_count_zero(): void
    {
        $result = $this->render("{{ t('blog.post_count', {}, 0) }}");

        $this->assertSame('No posts', $result);
    }

    public function test_tc_function_with_component_prefix(): void
    {
        $result = $this->render(
            "{{ tc('label', 'MyComponent') }}"
        );

        $this->assertSame('Component Label', $result);
    }

    public function test_tc_falls_back_to_unprefixed(): void
    {
        $result = $this->render("{{ tc('title', 'Unknown') }}");

        // 'unknown.title' doesn't exist, should return key
        $this->assertSame('unknown.title', $result);
    }

    public function test_t_missing_key_returns_key(): void
    {
        $result = $this->render("{{ t('blog.nonexistent') }}");

        $this->assertSame('blog.nonexistent', $result);
    }
}
