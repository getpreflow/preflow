<?php

declare(strict_types=1);

namespace Preflow\Ai\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\AiServiceProvider;
use Preflow\Ai\Console\AiCheckCommand;
use Preflow\Ai\Console\AiUsageCommand;
use Preflow\Ai\Settings\AiSettingsStore;
use Preflow\Ai\Usage\Pricing;
use Preflow\Ai\Usage\UsageLog;
use Preflow\Core\Application;

final class AiServiceProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ai_provider_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Ai', 'debug' => 1, 'engine' => 'twig', 'key' => 'ai-test-key'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\Preflow\\Ai\\AiServiceProvider::class];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    public function test_absent_config_means_disabled(): void
    {
        $c = $this->app()->container();

        $this->assertInstanceOf(AiServiceProvider::class, new AiServiceProvider());
        $this->assertFalse($c->get(Ai::class)->enabled());
        $this->assertSame($c->get(Ai::class), $c->get(Ai::class));
        $this->assertInstanceOf(UsageLog::class, $c->get(UsageLog::class));
        $this->assertInstanceOf(AiSettingsStore::class, $c->get(AiSettingsStore::class));
        $this->assertTrue($c->get(Pricing::class)->has('claude-sonnet-5-5'));
    }

    public function test_config_file_is_read_and_storage_is_relative_to_the_app(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'keys' => ['claude' => 'PREFLOW_AI_PROVIDER_TEST'], 'pricing' => ['x' => [1, 2]], 'storage' => 'var/ai'];\n");
        $_ENV['PREFLOW_AI_PROVIDER_TEST'] = 'k';
        try {
            $c = $this->app()->container();
            $this->assertTrue($c->get(Ai::class)->enabled());
            $this->assertTrue($c->get(Pricing::class)->has('x'));
            $c->get(AiSettingsStore::class)->put('tuning', ['a']);
            $this->assertFileExists($this->dir . '/var/ai/settings.json');
        } finally {
            unset($_ENV['PREFLOW_AI_PROVIDER_TEST']);
        }
    }

    public function test_commands_registered(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $listed = $composer['extra']['preflow']['commands'] ?? [];

        $this->assertSame([AiCheckCommand::class, AiUsageCommand::class], $listed);
        $this->assertSame('ai:check', (new AiCheckCommand())->getName());
        $this->assertSame('ai:usage', (new AiUsageCommand())->getName());
    }
}
