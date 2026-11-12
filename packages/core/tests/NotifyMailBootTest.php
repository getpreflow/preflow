<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

require_once __DIR__ . '/Fixtures/MailEngineProvider.php';

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Tests\Fixtures\MailEngineProvider;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailConfig;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailLayout;
use Preflow\Notify\Mail\TemplateMailLayout;
use Preflow\View\TemplateEngineInterface;

/** With config/notify.php, core binds the mail layer next to the Notifier: MailConfig, MailLayout, Mailer and @notify. */
final class NotifyMailBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/notifymail_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k'];\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    public function test_with_view_the_layout_is_the_template_layout(): void
    {
        $this->notifyConfig("['mail' => ['accent' => '#0f766e', 'from' => 'Site <s@b.test>']]");

        $container = $this->boot();

        $this->assertInstanceOf(TemplateMailLayout::class, $container->get(MailLayout::class));
        $this->assertSame('#0f766e', $container->get(MailConfig::class)->accent);

        $html = $container->get(TemplateEngineInterface::class)->render('@notify/mail/layout.twig', [
            'lang' => 'de', 'subject' => 'Hallo', 'accent' => '#0f766e', 'paragraphs' => ['Eins'],
            'action' => ['label' => 'Los', 'url' => 'https://site.test/x'],
        ]);
        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('data-mail-action href="https://site.test/x"', $html);

        $email = $container->get(Mailer::class)->compose(new MailContent('Hallo', 'Eins', 'de', new MailAction('Los', 'https://site.test/x')), 'a@b.test');
        $this->assertStringContainsString('background:#0f766e', (string) $email->html);
        $this->assertSame('Site <s@b.test>', $email->from);
    }

    public function test_without_a_mail_section_the_defaults_apply(): void
    {
        $this->notifyConfig('[]');

        $this->assertEquals(new MailConfig(), $this->boot()->get(MailConfig::class));
    }

    public function test_an_engine_bound_after_notify_gets_the_layout_and_the_namespace(): void
    {
        $this->notifyConfig('[]');
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . MailEngineProvider::class . "::class];\n");

        $container = $this->boot();
        $email = $container->get(Mailer::class)->compose(new MailContent('S', 'T', action: new MailAction('Go', 'https://site.test/x')), 'a@b.test');

        $this->assertSame(MailEngineProvider::$engine, $container->get(TemplateEngineInterface::class));
        $this->assertInstanceOf(TemplateMailLayout::class, $container->get(MailLayout::class));
        $this->assertStringContainsString('data-mail-action href="https://site.test/x"', (string) $email->html);
    }

    public function test_without_notify_config_no_mail_layer(): void
    {
        $container = $this->boot();

        $this->assertFalse($container->bound(Mailer::class));
        $this->assertFalse($container->bound(MailLayout::class));
    }

    private function notifyConfig(string $extra): void
    {
        file_put_contents(
            $this->dir . '/config/notify.php',
            "<?php\nreturn ['transports' => ['memory' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'memory']]] + {$extra};\n",
        );
    }

    private function boot(): Container
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app->container();
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
