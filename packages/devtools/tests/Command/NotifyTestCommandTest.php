<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Command\NotifyTestCommand;
use Preflow\Notify\Email;
use Preflow\Notify\Sms;
use Preflow\Notify\TransportFactory;

final class NotifyTestCommandTest extends TestCase
{
    private string $dir;

    private ?Application $app = null;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/notifycmd_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents(
            $this->dir . '/config/app.php',
            "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n",
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/config/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir . '/config');
        @rmdir($this->dir);
    }

    public function test_sends_email_and_prints_transport(): void
    {
        $this->writeNotifyConfig(['email' => ['transport' => 'memory']]);

        [$code, $output] = $this->runCommand(['--to=a@b.test']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Sent via memory', $output);
        $sent = $this->memory()->sent();
        $this->assertCount(1, $sent);
        $this->assertInstanceOf(Email::class, $sent[0]->message);
        $this->assertSame('Preflow notify test', $sent[0]->message->subject);
        $this->assertSame(['a@b.test'], $sent[0]->message->to);
    }

    public function test_sms_channel(): void
    {
        $this->writeNotifyConfig(['sms' => ['transport' => 'memory']]);

        [$code] = $this->runCommand(['--channel=sms', '--to=+41790000000']);

        $this->assertSame(0, $code);
        $this->assertInstanceOf(Sms::class, $this->memory()->sent()[0]->message);
        $this->assertSame('Preflow notify test', $this->memory()->sent()[0]->message->text);
    }

    public function test_missing_to_returns_1(): void
    {
        $this->writeNotifyConfig(['email' => ['transport' => 'memory']]);

        [$code] = $this->runCommand([]);

        $this->assertSame(1, $code);
    }

    public function test_reports_transport_error_with_exit_code_1(): void
    {
        $this->writeNotifyConfig(['email' => ['transport' => 'smtp', 'from' => 'site@x.test']]);

        [$code] = $this->runCommand(['--to=a@b.test']);

        $this->assertSame(1, $code);
    }

    public function test_failover_fallback_reports_primary_failure_and_exits_1(): void
    {
        $this->writeNotifyConfig(['email' => ['transport' => 'mail', 'from' => 'site@x.test']]);

        [$code, $output, $errors] = $this->runCommand(['--to=a@b.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Sent via memory', $output);
        $this->assertStringContainsString('smtp', $errors);
        $this->assertStringContainsString('dsn is empty', $errors);
    }

    public function test_missing_notify_config_returns_1(): void
    {
        [$code] = $this->runCommand(['--to=a@b.test']);

        $this->assertSame(1, $code);
    }

    /** @param array<string, mixed> $channels */
    private function writeNotifyConfig(array $channels): void
    {
        $config = [
            'transports' => [
                'memory' => ['driver' => 'memory'],
                'smtp' => ['driver' => 'symfony-mailer', 'dsn' => ''],
                'mail' => ['driver' => 'failover', 'transports' => ['smtp', 'memory']],
            ],
            'channels' => $channels,
        ];
        file_put_contents($this->dir . '/config/notify.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runCommand(array $args): array
    {
        $stderr = fopen('php://memory', 'w+');
        $command = new NotifyTestCommand(function (): Application {
            $this->app = Application::create($this->dir);
            $this->app->boot();

            return $this->app;
        }, $stderr);

        ob_start();
        $code = $command->execute($args);
        $output = (string) ob_get_clean();
        rewind($stderr);
        $errors = (string) stream_get_contents($stderr);
        fclose($stderr);

        return [$code, $output, $errors];
    }

    private function memory(): \Preflow\Notify\Transport\InMemoryTransport
    {
        return $this->app->container()->get(TransportFactory::class)->get('memory');
    }
}
