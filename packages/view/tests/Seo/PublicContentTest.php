<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Preflow\View\NonceGenerator;
use Preflow\View\Seo\PageMeta;
use Preflow\View\Seo\PublicPages;
use Preflow\View\Seo\SchemaRegistry;
use Preflow\View\Seo\SeoConfig;
use Preflow\View\Seo\SeoContext;
use Preflow\View\Seo\SeoMiddleware;
use Preflow\View\Seo\SeoSite;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A page whose content is public although its response is private (`seo({public_content: true})`). */
final class PublicContentTest extends TestCase
{
    private SeoContext $context;

    protected function setUp(): void
    {
        $this->context = new SeoContext();
    }

    /** The response a handler gives after the page's template called `seo($values)`. */
    private function render(string $cacheControl, array $values): ResponseInterface
    {
        $context = $this->context;
        $handler = new class ($context, $cacheControl, $values) implements RequestHandlerInterface {
            public function __construct(private SeoContext $context, private string $cacheControl, private array $values) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->context->mergeTemplate($this->values);

                return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => $this->cacheControl],
                    '<html><head><title>Oak bowl</title></head><body><main><p>Turned oak.</p></main></body></html>');
            }
        };
        $middleware = new SeoMiddleware(
            SeoConfig::fromArray(['enabled' => true]),
            $context,
            new PublicPages(),
            new SchemaRegistry(),
            new SeoSite(),
            new NonceGenerator(),
            'https://site.test',
            ['en'],
            'en',
            'App',
            false,
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-05'),
            sentHeaders: static fn (): array => [],
        );

        return $middleware->process((new Psr17Factory())->createServerRequest('GET', 'https://site.test/objects/oak-bowl'), $handler);
    }

    public function test_a_flagged_private_page_gets_cards_and_json_ld_but_no_etag(): void
    {
        foreach (['private, no-cache', 'no-store'] as $cacheControl) {
            $response = $this->render($cacheControl, ['description' => 'A bowl', 'public_content' => true]);
            $html = (string) $response->getBody();

            $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $html, $cacheControl);
            $this->assertStringContainsString('<meta property="og:url" content="https://site.test/objects/oak-bowl">', $html, $cacheControl);
            $this->assertStringContainsString('<meta property="og:title" content="Oak bowl">', $html, $cacheControl);
            $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html, $cacheControl);
            $this->assertStringContainsString('<script type="application/ld+json">', $html, $cacheControl);
            // Still one visitor's response: no ETag, its Cache-Control as the page sent it.
            $this->assertFalse($response->hasHeader('ETag'), $cacheControl);
            $this->assertSame($cacheControl, $response->getHeaderLine('Cache-Control'));
        }
    }

    public function test_a_private_page_without_the_flag_gets_no_cards(): void
    {
        $html = (string) $this->render('private, no-cache', ['description' => 'A bowl'])->getBody();

        $this->assertStringContainsString('<meta name="robots" content="max-image-preview:large">', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('twitter:', $html);
        $this->assertStringNotContainsString('ld+json', $html);
    }

    public function test_noindex_wins_over_the_flag(): void
    {
        $html = (string) $this->render('private, no-cache', ['public_content' => true, 'noindex' => true])->getBody();

        $this->assertStringContainsString('<meta name="robots" content="noindex">', $html);
        $this->assertStringNotContainsString('og:', $html);
        $this->assertStringNotContainsString('ld+json', $html);
    }

    public function test_the_flag_survives_a_merge_over_a_records_meta(): void
    {
        // A Folio record sets the meta first; the template's seo() merges only the flag over it.
        $this->context->set(new PageMeta(title: 'Oak bowl', description: 'From the record'));
        $this->context->mergeTemplate(['public_content' => true]);
        $this->context->mergeTemplate(['title' => 'Oak bowl, turned']);

        $meta = $this->context->get();
        $this->assertTrue($meta?->publicContent);
        $this->assertSame('From the record', $meta->description);
        $this->assertSame('Oak bowl, turned', $meta->title);
        $this->assertFalse((new PageMeta())->publicContent);
    }
}
