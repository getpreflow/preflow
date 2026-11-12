<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Mail;

use PHPUnit\Framework\TestCase;
use Preflow\Notify\Mail\BasicMailLayout;
use Preflow\Notify\Mail\MailAction;
use Preflow\Notify\Mail\MailContent;
use Preflow\Notify\Mail\TemplateMailLayout;
use Preflow\Twig\TwigEngine;
use Preflow\View\AssetCollector;
use Preflow\View\NonceGenerator;

final class TemplateMailLayoutTest extends TestCase
{
    private TwigEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new TwigEngine([], new AssetCollector(new NonceGenerator()));
        $this->engine->addNamespace('notify', dirname(__DIR__, 2) . '/templates');
        $this->engine->addNamespace('fixtures', __DIR__ . '/Fixtures');
    }

    public function test_template_layout_gets_the_old_variables(): void
    {
        $content = new MailContent('Hallo', "Eins\n\nZwei", 'de', new MailAction('Los', 'https://site.test/x'), ['extra' => 'x']);

        $html = (new TemplateMailLayout($this->engine))->html($content, '#0f766e', '@fixtures/vars.twig');

        $this->assertSame('de|Hallo|#0f766e|2|Los|x', trim($html));
    }

    public function test_default_template_is_the_neutral_layout(): void
    {
        $content = new MailContent('Hallo', "Willkommen <b>x</b>\n\nsiehe https://site.test/a?b=1&c=2", 'de', new MailAction('Los <geht\'s>', 'https://site.test/a?b=1&c=2'));

        $html = (new TemplateMailLayout($this->engine))->html($content, '#0f766e');

        $this->assertSame(
            $this->normalize((new BasicMailLayout())->html($content, '#0f766e')),
            $this->normalize($html),
        );
        $this->assertStringContainsString('data-mail-action href=', $html);
    }

    public function test_default_template_without_action(): void
    {
        $content = new MailContent('S', 'T');

        $html = (new TemplateMailLayout($this->engine))->html($content, '#1a1a1a');

        $this->assertSame($this->normalize((new BasicMailLayout())->html($content, '#1a1a1a')), $this->normalize($html));
        $this->assertStringNotContainsString('data-mail-action', $html);
    }

    /** The two forms differ only in whitespace between tags and in how they escape an apostrophe. */
    private function normalize(string $html): string
    {
        $html = str_replace(['&#039;', '&apos;'], "'", $html);

        return trim((string) preg_replace('/>\s+</', '><', (string) preg_replace('/\{#.*?#\}/s', '', $html)));
    }
}
