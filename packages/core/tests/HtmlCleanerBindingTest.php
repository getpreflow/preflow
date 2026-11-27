<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\Html\SymfonyHtmlCleaner;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;

/**
 * The application hands its container's HtmlCleaner binding to the DataManager (0.93): a site
 * binds its own cleaner and every write of rich text uses it; without one, the core's.
 */
final class HtmlCleanerBindingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/html_cleaner_binding_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default' => 'json', 'drivers' => ['json' => ['path' => '{$this->dir}/json']]];\n");
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (in_array(basename($f), ['.', '..'], true)) {
                    continue;
                }
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }

    private static function page(): TypeDefinition
    {
        return new TypeDefinition('page', 'pages', 'default', ['body' => new TypeFieldDefinition('body', 'richtext')]);
    }

    public function test_a_bound_cleaner_replaces_the_default(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $dm = $app->container()->get(DataManager::class);
        $this->assertInstanceOf(SymfonyHtmlCleaner::class, $dm->htmlCleaner(), 'nothing bound: the core cleaner');

        $app->container()->instance(HtmlCleaner::class, new class implements HtmlCleaner {
            public function clean(string $html): string
            {
                return 'site:' . strip_tags($html);
            }
        });
        $dm->saveType(DynamicRecord::fromArray(self::page(), ['uuid' => 'a', 'body' => '<p onclick="x()">Hi</p>']), false);

        $this->assertSame('site:Hi', ($dm->driver('default')->findOne('pages', 'a') ?? [])['body'] ?? null);
    }
}
