<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Psr\Http\Message\ResponseInterface;

final class I18nBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/i18n_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default'=>'en','available'=>['en','de'],'fallback'=>'en','url_strategy'=>'prefix'];\n");
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body>locale={{ current_locale() }}</body></html>');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/app/pages/index.twig');
        @unlink($this->dir . '/config/app.php');
        @unlink($this->dir . '/config/i18n.php');
        @rmdir($this->dir . '/app/pages');
        @rmdir($this->dir . '/app');
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_boots_with_config_and_without_lang_dir(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir . '/lang');

        $body = (string) $this->get('/de/')->getBody();

        $this->assertStringContainsString('locale=de', $body);
    }

    public function test_no_cookie_by_default(): void
    {
        $this->assertFalse($this->get('/de/')->hasHeader('Set-Cookie'));
    }

    private function get(string $uri): ResponseInterface
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }
}
