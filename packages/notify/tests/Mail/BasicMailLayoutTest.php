<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Mail\BasicMailLayout;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailContent;

final class BasicMailLayoutTest extends TestCase
{
    public function test_german_mail_with_an_action(): void
    {
        $html = (new BasicMailLayout())->html(
            new MailContent('Hallo', "Willkommen <script>x</script>\n\nWeiter", 'de', new MailAction('Los <geht\'s>', 'https://site.test/a?b=1&c=2')),
            '#0f766e',
        );

        $this->assertStringContainsString('<html lang="de">', $html);
        $this->assertStringContainsString('<title>Hallo</title>', $html);
        $this->assertStringContainsString('height:3px;background:#0f766e', $html);
        $this->assertStringContainsString('data-mail-action href="https://site.test/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('>Los &lt;geht&apos;s&gt;</a>', $html);
        $this->assertStringContainsString('<p data-mail-action-url style="margin:0 0 16px;font-size:13px;color:#666666;word-break:break-all">https://site.test/a?b=1&amp;c=2</p>', $html);
        $this->assertStringContainsString('max-width:560px', $html);
        $this->assertStringContainsString('color:#1a1a1a', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_no_action_no_button(): void
    {
        $html = (new BasicMailLayout())->html(new MailContent('S', 'T'), '#1a1a1a');

        $this->assertStringNotContainsString('data-mail-action', $html);
        $this->assertStringContainsString('<html lang="en">', $html);
    }

    public function test_accent_and_attributes_are_escaped(): void
    {
        $html = (new BasicMailLayout())->html(new MailContent("S\"<x>", 'T', '"de'), '"><b>');

        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('<x>', $html);
        $this->assertStringContainsString('lang="&quot;de"', $html);
    }
}
