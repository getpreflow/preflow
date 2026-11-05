<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\View\Audit\RenderedPage;

final class RenderedPageTest extends TestCase
{
    public function test_header_is_case_insensitive_and_joins_values(): void
    {
        $page = new RenderedPage('https://site.test/', 'en', 200, [
            'Content-Type' => ['text/html; charset=UTF-8'],
            'Link' => ['</a>; rel="alternate"', '</b>; rel="alternate"'],
        ], '');

        $this->assertSame('text/html; charset=UTF-8', $page->header('content-type'));
        $this->assertSame('</a>; rel="alternate", </b>; rel="alternate"', $page->header('LINK'));
        $this->assertSame('', $page->header('X-Missing'));
    }

    public function test_dom_parses_html5_with_utf8_once_and_quietly(): void
    {
        $html = '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Über uns – Spiele</title></head>'
            . '<body><nav><a href="/x">x</a></nav><main><h1>Grüße</h1><unknown-tag>t</unknown-tag></main></body></html>';
        $page = new RenderedPage('https://site.test/de/ueber', 'de', 200, ['Content-Type' => ['text/html']], $html);

        $before = libxml_use_internal_errors();
        $dom = $page->dom();

        $this->assertInstanceOf(\DOMDocument::class, $dom);
        $this->assertSame($dom, $page->dom());
        $this->assertSame('Über uns – Spiele', $dom->getElementsByTagName('title')->item(0)?->textContent);
        $this->assertSame('Grüße', $dom->getElementsByTagName('h1')->item(0)?->textContent);
        $this->assertSame([], libxml_get_errors());
        $this->assertSame($before, libxml_use_internal_errors());
    }

    public function test_dom_keeps_utf8_inside_script_and_style(): void
    {
        $html = '<!DOCTYPE html><html><head><title>Spiele für Groß und Klein</title>'
            . '<style>.q::before { content: "–"; }</style>'
            . '<script type="application/ld+json">{"name":"Über uns"}</script></head>'
            . '<body><p>Ärger – Spaß</p></body></html>';
        $page = new RenderedPage('https://site.test/de/ueber', 'de', 200, ['Content-Type' => ['text/html; charset=UTF-8']], $html);

        $dom = $page->dom();

        $this->assertNotNull($dom);
        $this->assertSame('Spiele für Groß und Klein', $dom->getElementsByTagName('title')->item(0)?->textContent);
        $this->assertSame('{"name":"Über uns"}', $dom->getElementsByTagName('script')->item(0)?->textContent);
        $this->assertSame('.q::before { content: "–"; }', $dom->getElementsByTagName('style')->item(0)?->textContent);
        $this->assertSame('Ärger – Spaß', $dom->getElementsByTagName('p')->item(0)?->textContent);
        // The encoding hint leaves no node behind.
        foreach ($dom->childNodes as $node) {
            $this->assertNotInstanceOf(\DOMProcessingInstruction::class, $node);
        }
    }

    public function test_dom_is_null_for_an_empty_or_non_html_body(): void
    {
        $this->assertNull((new RenderedPage('u', 'en', 500, [], ''))->dom());
        $this->assertNull((new RenderedPage('u', 'en', 200, ['Content-Type' => ['application/json']], '{"a":1}'))->dom());
    }
}
