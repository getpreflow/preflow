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

    public function test_an_unknown_provider_stops_the_boot_naming_the_accepted_forms(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => false, 'provider' => 'openai'];\n");

        try {
            $this->app();
            $this->fail('expected the boot to stop');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"openai"', $e->getMessage());
            $this->assertStringContainsString("'claude', 'gemini', 'fake'", $e->getMessage());
        }
    }

    public function test_the_fake_provider_runs_ai_check_without_a_key(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'fake', 'storage' => 'var/ai'];\n");
        $app = $this->app();

        $out = fopen('php://memory', 'w+');
        ob_start();
        $code = (new AiCheckCommand(static fn (): Application => $app, $out))->execute([]);
        $printed = (string) ob_get_clean();

        $this->assertSame(0, $code, $printed);
        $this->assertStringContainsString('Provider: fake', $printed);
        $this->assertStringContainsString('Key:      not used', $printed);
        $this->assertStringContainsString('cost unknown', $printed);
        $this->assertStringContainsString("OK\n", $printed);
    }

    public function test_on_a_live_site_the_fake_is_off_and_ai_check_says_why(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Ai', 'debug' => 0, 'engine' => 'twig', 'key' => 'ai-test-key'];\n");
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'fake', 'storage' => 'var/ai'];\n");
        $app = $this->app();
        $this->assertFalse($app->container()->get(Ai::class)->enabled());

        $err = fopen('php://memory', 'w+');
        ob_start();
        $code = (new AiCheckCommand(static fn (): Application => $app, $err))->execute([]);
        ob_end_clean();
        rewind($err);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('only while developing', (string) stream_get_contents($err));
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_the_warning_goes_to_the_app_s_bound_logger(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Ai', 'debug' => 0, 'engine' => 'twig', 'key' => 'ai-test-key'];\n");
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'fake', 'storage' => 'var/ai'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\Preflow\\Ai\\AiServiceProvider::class, \\" . MemoryLoggerProvider::class . "::class];\n");
        ini_set('error_log', $this->dir . '/php.log');
        $c = $this->app()->container();

        $c->get(Ai::class)->enabled();

        $logger = $c->get(\Psr\Log\LoggerInterface::class);
        $this->assertCount(1, $logger->warnings);
        $this->assertStringContainsString('only while developing', $logger->warnings[0]);
        $this->assertFileDoesNotExist($this->dir . '/php.log', 'not also in the PHP log');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function test_without_a_logger_the_warning_goes_to_the_php_log(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Ai', 'debug' => 0, 'engine' => 'twig', 'key' => 'ai-test-key'];\n");
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'fake', 'storage' => 'var/ai'];\n");
        ini_set('error_log', $this->dir . '/php.log');

        $this->app()->container()->get(Ai::class)->enabled();

        $this->assertStringContainsString('only while developing', (string) file_get_contents($this->dir . '/php.log'));
    }

    public function test_on_a_live_site_the_fake_answers_with_allow_live(): void
    {
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Ai', 'debug' => 0, 'engine' => 'twig', 'key' => 'ai-test-key'];\n");
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'fake', 'options' => ['fake' => ['allow_live' => true]], 'storage' => 'var/ai'];\n");

        $this->assertTrue($this->app()->container()->get(Ai::class)->enabled());
    }

    public function test_a_factory_class_is_built_by_the_container_after_the_providers(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => \\" . ContainerBuiltFactory::class . "::class, 'storage' => 'var/ai'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\Preflow\\Ai\\AiServiceProvider::class, \\" . EndpointProvider::class . "::class];\n");
        $c = $this->app()->container();

        $ai = $c->get(Ai::class);
        $this->assertTrue($ai->enabled());
        $this->assertSame('Ai at http://127.0.0.1:11434', $ai->client()->complete(new \Preflow\Ai\AiRequest('x', 'y'))->text);
    }
}

/** A service an app's provider binds, which the factory below takes. */
final class LocalEndpoint
{
    public function __construct(public readonly string $url) {}
}

final class EndpointProvider extends \Preflow\Core\Container\ServiceProvider
{
    public function register(\Preflow\Core\Container\Container $container): void
    {
        $container->singleton(LocalEndpoint::class, static fn (): LocalEndpoint => new LocalEndpoint('http://127.0.0.1:11434'));
    }
}

final class ContainerBuiltFactory implements \Preflow\Ai\AiClientFactory
{
    public function __construct(
        private readonly LocalEndpoint $endpoint,
        #[\Preflow\Core\Container\Attributes\Config('app.name')] private readonly string $appName,
    ) {}

    public function available(array $options): bool
    {
        return true;
    }

    public function create(array $options): \Preflow\Ai\AiClient
    {
        return new \Preflow\Ai\Testing\FakeAiClient([new \Preflow\Ai\AiResponse("{$this->appName} at {$this->endpoint->url}", \Preflow\Ai\StopReason::Complete, new \Preflow\Ai\TokenUsage(), 'local', 'local-model')]);
    }
}

/** An app's PSR-3 logger, keeping warnings in memory. */
final class MemoryLogger extends \Psr\Log\AbstractLogger
{
    /** @var list<string> */
    public array $warnings = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === \Psr\Log\LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        }
    }
}

final class MemoryLoggerProvider extends \Preflow\Core\Container\ServiceProvider
{
    public function register(\Preflow\Core\Container\Container $container): void
    {
        $container->singleton(\Psr\Log\LoggerInterface::class, static fn (): MemoryLogger => new MemoryLogger());
    }
}
