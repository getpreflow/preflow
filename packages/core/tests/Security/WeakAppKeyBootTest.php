<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Context\Contexts;
use Preflow\Core\Security\WeakAppKey;
use Preflow\Testing\ChecksFrameBalance;

/**
 * No weak app keys (owner decision, 0.81): in production a web app refuses to start with an
 * app.key that cannot sign, and says what is wrong and how to fix it; debug mode warns once and
 * starts; the console starts (so `php preflow key:generate` can fix the key).
 */
final class WeakAppKeyBootTest extends TestCase
{
    use ChecksFrameBalance;

    private string $dir;

    protected function setUp(): void
    {
        Contexts::reset();
        $this->dir = sys_get_temp_dir() . '/weak_app_key_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/config', 0777, true);
        @mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/app/pages/index.twig', '<p>home</p>');
        ini_set('error_log', $this->dir . '/php.log');
    }

    protected function tearDown(): void
    {
        Contexts::reset();
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return iterable<string, array{string, string}> */
    public static function weakKeys(): iterable
    {
        yield 'a space' => [' ', 'app.key is empty'];
        yield 'short' => ['short-key', 'app.key is 9 bytes long; it needs at least 32'];
        yield 'the example key' => ['change-this-to-a-random-32-char-string!', 'app.key is a public example key that anyone can look up'];
        yield 'the built-in default' => ['preflow-default-key-change-me!!', 'app.key is a public example key that anyone can look up'];
    }

    #[RunInSeparateProcess]
    #[DataProvider('weakKeys')]
    public function test_production_refuses_to_start_with_a_weak_key(string $key, string $problem): void
    {
        $app = $this->app($key, debug: false, console: false);

        try {
            $app->boot();
            $this->fail('the app started');
        } catch (WeakAppKey $e) {
            $this->assertStringContainsString($problem, $e->getMessage());
            $this->assertStringContainsString('php preflow key:generate', $e->getMessage(), 'it names the fix');
            $this->assertStringContainsString("php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'", $e->getMessage(), 'and one without devtools');
            $this->assertStringContainsString('cache:clear pages', $e->getMessage(), 'stored pages carry placeholders signed with the old key');
            $this->assertStringNotContainsString('signed out', $e->getMessage(), 'sessions do not depend on the key');
            if (trim($key) !== '') {
                $this->assertStringNotContainsString(trim($key), str_replace('app.key is a public example key', '', $e->getMessage()), 'never the key itself');
            }
        }
    }

    #[RunInSeparateProcess]
    public function test_debug_mode_warns_once_and_starts(): void
    {
        $app = $this->app('short-key', debug: true, console: false);

        $app->boot();

        $this->assertSame(200, $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'))->getStatusCode());
        $log = (string) file_get_contents($this->dir . '/php.log');
        $this->assertSame(1, substr_count($log, 'app.key cannot sign'), 'said once');
    }

    #[RunInSeparateProcess]
    public function test_the_console_starts_with_a_weak_key_so_it_can_be_fixed(): void
    {
        $app = $this->app('short-key', debug: false, console: true);

        $app->boot();

        $this->addToAssertionCount(1);
    }

    #[RunInSeparateProcess]
    public function test_a_key_that_can_sign_starts_in_production(): void
    {
        $app = $this->app(bin2hex(random_bytes(16)), debug: false, console: false);

        $app->boot();

        $this->assertSame(200, $app->handle(new \Nyholm\Psr7\ServerRequest('GET', '/'))->getStatusCode());
    }

    private function app(string $key, bool $debug, bool $console): Application
    {
        file_put_contents($this->dir . '/config/app.php', '<?php return ' . var_export(['name' => 'k', 'debug' => $debug ? 1 : 0, 'engine' => 'twig', 'key' => $key], true) . ";\n");
        $app = Application::create($this->dir);
        $app->setRunningInConsole($console);

        return $app;
    }
}
