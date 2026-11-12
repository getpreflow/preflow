<?php

declare(strict_types=1);

namespace Preflow\Notify\Tests\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Notify\Mail\MailText;

final class MailTextTest extends TestCase
{
    public function test_one_line_collapses_control_characters(): void
    {
        $this->assertSame('a b c', MailText::oneLine("a\r\nb\u{2028}c"));
        $this->assertSame('x', MailText::oneLine("  x\t"));
    }

    public function test_paragraphs_are_escaped_and_urls_linked(): void
    {
        $this->assertSame(
            ['Hi &lt;b&gt;x&lt;/b&gt;', 'see <a href="https://ex.test/a?b=1" style="color:#123456">https://ex.test/a?b=1</a>'],
            MailText::paragraphs("Hi <b>x</b>\n\nsee https://ex.test/a?b=1", '#123456'),
        );
    }

    public function test_paragraphs_link_only_the_listed_urls(): void
    {
        $this->assertSame(
            ['Open <a href="https://site.test/invite/x?a=1&amp;b=2" style="color:#000">https://site.test/invite/x?a=1&amp;b=2</a>', 'see https://evil.test/&lt;x'],
            MailText::paragraphs("Open https://site.test/invite/x?a=1&b=2\n\nsee https://evil.test/<x", '#000', ['https://site.test/invite/x?a=1&b=2']),
        );
    }

    public function test_paragraphs_with_an_empty_list_link_nothing(): void
    {
        $this->assertSame(['see https://ex.test/a'], MailText::paragraphs('see https://ex.test/a', '#000', []));
    }

    public function test_paragraphs_keep_single_line_breaks(): void
    {
        $this->assertSame(['a<br>' . "\n" . 'b'], MailText::paragraphs("a\nb", '#000'));
    }

    public function test_paragraphs_escape_the_accent(): void
    {
        $this->assertSame(
            ['<a href="http://x.test" style="color:&quot;&gt;">http://x.test</a>'],
            MailText::paragraphs('http://x.test', '">'),
        );
    }

    public function test_absolute_url_from_a_path(): void
    {
        $this->assertSame('https://site.test/invite/x', MailText::absoluteUrl('/invite/x', 'https://site.test/'));
        $this->assertSame('https://site.test/a', MailText::absoluteUrl('/a', 'https://site.test'));
    }

    public function test_absolute_url_keeps_an_absolute_http_url(): void
    {
        $this->assertSame('https://other.test/x?y=1', MailText::absoluteUrl('https://other.test/x?y=1', 'https://site.test'));
        $this->assertSame('http://other.test', MailText::absoluteUrl('http://other.test', 'https://site.test'));
    }

    #[DataProvider('badLinks')]
    public function test_absolute_url_rejects_anything_else(string $link): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The link must be a path or an absolute http(s) URL.');

        MailText::absoluteUrl($link, 'https://site.test');
    }

    /** @return iterable<string, array{string}> */
    public static function badLinks(): iterable
    {
        yield 'protocol-relative' => ['//evil.test'];
        yield 'javascript' => ['javascript:x'];
        yield 'line break' => ["/a\nb"];
        yield 'backslash' => ['/\\evil.test'];
        yield 'space' => ['/a b'];
        yield 'no host' => ['https:///x'];
        yield 'relative' => ['invite/x'];
        yield 'empty' => [''];
    }
}
