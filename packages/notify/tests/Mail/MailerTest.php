<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Mail\BasicMailLayout;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailConfig;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\Mailer;
use Preflow\Notify\Mail\MailLayout;
use Preflow\Notify\Notifier;
use Preflow\Notify\TransportFactory;

final class MailerTest extends TestCase
{
    private TransportFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new TransportFactory(['memory' => ['driver' => 'memory']], sys_get_temp_dir());
    }

    public function test_compose_builds_the_email(): void
    {
        $content = new MailContent(
            subject: "Hello\r\nBcc: x@evil.test",
            text: "First <b>line</b>\n\nSecond",
            action: new MailAction('Open', 'https://site.test/open'),
        );

        $email = $this->mailer()->compose($content, 'a@b.test');

        $this->assertSame('Hello Bcc: x@evil.test', $email->subject);
        $this->assertSame("First <b>line</b>\n\nSecond", $email->text);
        $this->assertSame(['a@b.test'], $email->to);
        $this->assertStringContainsString('First &lt;b&gt;line&lt;/b&gt;', (string) $email->html);
        $this->assertStringContainsString('>Second</p>', (string) $email->html);
        $this->assertStringContainsString('data-mail-action href="https://site.test/open"', (string) $email->html);
        $this->assertStringContainsString('>Open</a>', (string) $email->html);
    }

    public function test_default_accent_is_neutral(): void
    {
        $email = $this->mailer()->compose(new MailContent('S', 'T'), 'a@b.test');

        $this->assertStringContainsString('#1a1a1a', (string) $email->html);
    }

    public function test_accent_argument_beats_the_config(): void
    {
        $mailer = $this->mailer(new MailConfig(accent: '#0f766e'));

        $this->assertStringContainsString('background:#0f766e', (string) $mailer->compose(new MailContent('S', 'T'), 'a@b.test')->html);
        $this->assertStringContainsString('background:#abcdef', (string) $mailer->compose(new MailContent('S', 'T'), 'a@b.test', accent: '#abcdef')->html);
    }

    public function test_from_precedence(): void
    {
        $content = new MailContent('S', 'T');

        $this->assertNull($this->mailer()->compose($content, 'a@b.test')->from);
        $this->assertSame('Site <c@b.test>', $this->mailer(new MailConfig(from: 'Site <c@b.test>'))->compose($content, 'a@b.test')->from);
        $this->assertSame('Me <m@b.test>', $this->mailer(new MailConfig(from: 'Site <c@b.test>'))->compose($content, 'a@b.test', from: 'Me <m@b.test>')->from);
    }

    public function test_template_falls_back_to_the_configured_layout(): void
    {
        $layout = new class implements MailLayout {
            public array $calls = [];

            public function html(MailContent $content, string $accent, ?string $template = null): string
            {
                $this->calls[] = [$accent, $template];

                return '<p>x</p>';
            }
        };
        $mailer = new Mailer($this->notifier(), $layout, new MailConfig(layout: '@site/mail.twig'));

        $mailer->compose(new MailContent('S', 'T'), 'a@b.test');
        $mailer->compose(new MailContent('S', 'T'), 'a@b.test', template: '@other/mail.twig', accent: '#000');

        $this->assertSame([['#1a1a1a', '@site/mail.twig'], ['#000', '@other/mail.twig']], $layout->calls);
    }

    public function test_send_delivers_through_the_notifier(): void
    {
        $sent = $this->mailer()->send(new MailContent('S', 'T'), 'a@b.test');

        $this->assertSame('memory', $sent->transport);
        $this->assertCount(1, $this->factory->get('memory')->sent());
    }

    public function test_send_later_queues_until_flushed(): void
    {
        $notifier = $this->notifier();
        $mailer = new Mailer($notifier, new BasicMailLayout(), new MailConfig());

        $mailer->sendLater(new MailContent('S', 'T'), 'a@b.test');
        $this->assertCount(0, $this->factory->get('memory')->sent());

        $notifier->flushDeferred();
        $this->assertCount(1, $this->factory->get('memory')->sent());
    }

    public function test_send_later_reports_a_failure_to_the_callback(): void
    {
        $notifier = new Notifier($this->factory, [], defer: false);
        $mailer = new Mailer($notifier, new BasicMailLayout(), new MailConfig());
        $failure = null;

        $mailer->sendLater(new MailContent('S', 'T'), 'a@b.test', function (\Throwable $e) use (&$failure): void {
            $failure = $e;
        });

        $this->assertInstanceOf(\Preflow\Notify\TransportException::class, $failure);
    }

    public function test_config_from_array(): void
    {
        $config = MailConfig::fromArray(['accent' => '#0f766e', 'layout' => '@site/mail.twig', 'from' => "Site\n<c@b.test>"]);

        $this->assertSame('#0f766e', $config->accent);
        $this->assertSame('@site/mail.twig', $config->layout);
        $this->assertSame('Site <c@b.test>', $config->from);
    }

    public function test_config_defaults_for_missing_or_invalid_values(): void
    {
        $config = MailConfig::fromArray(['accent' => 'red;background:url(x)', 'layout' => '', 'from' => '  ']);

        $this->assertSame('#1a1a1a', $config->accent);
        $this->assertNull($config->layout);
        $this->assertNull($config->from);
        $this->assertEquals(new MailConfig(), MailConfig::fromArray([]));
    }

    private function mailer(?MailConfig $config = null): Mailer
    {
        return new Mailer($this->notifier(), new BasicMailLayout(), $config ?? new MailConfig());
    }

    private function notifier(): Notifier
    {
        return new Notifier($this->factory, ['email' => ['transport' => 'memory']]);
    }
}
