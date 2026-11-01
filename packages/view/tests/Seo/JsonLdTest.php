<?php

declare(strict_types=1);

namespace Preflow\View\Tests\Seo;

use PHPUnit\Framework\TestCase;
use Preflow\View\Seo\JsonLd;
use Preflow\View\Seo\Schema;

final class JsonLdTest extends TestCase
{
    public function test_encodes_a_graph(): void
    {
        $json = JsonLd::encode(['a' => ['@type' => 'WebSite', 'url' => 'https://example.com/']]);

        $this->assertSame('{"@context":"https://schema.org","@graph":[{"@type":"WebSite","url":"https://example.com/"}]}', $json);
    }

    public function test_escapes_markup_and_keeps_unicode(): void
    {
        $json = JsonLd::encode([['name' => "Zürich </script><b>'&\""]]);

        $this->assertStringNotContainsString('</script', $json);
        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('&', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringContainsString('Zürich', $json);
        $this->assertSame("Zürich </script><b>'&\"", json_decode($json, true)['@graph'][0]['name']);
    }

    public function test_schema_helpers_link_by_id(): void
    {
        $site = Schema::webSite('https://example.com/', 'Example', 'de', 'https://example.com/#owner');
        $owner = Schema::owner(['type' => 'Organization', 'name' => 'Acme', 'url' => null, 'logo' => 'https://example.com/logo.png', 'same_as' => ['https://x.example/acme']], 'https://example.com');
        $image = ['url' => 'https://example.com/a.jpg', 'width' => 1200, 'height' => 630, 'alt' => 'A'];
        $page = Schema::webPage('https://example.com/about', 'About', 'About us', 'de', 'https://example.com', $image);
        $crumbs = Schema::breadcrumbList([['name' => 'Home', 'url' => 'https://example.com/'], ['name' => 'About', 'url' => 'https://example.com/about']]);
        $article = Schema::article('https://example.com/news/x', 'X', 'About X', 'de', '2026-11-01', $image, 'https://example.com/#owner');

        $this->assertSame('https://example.com/#website', $site['@id']);
        $this->assertSame(['@id' => 'https://example.com/#owner'], $site['publisher']);
        $this->assertSame('https://example.com/#owner', $owner['@id']);
        $this->assertSame('Organization', $owner['@type']);
        $this->assertSame(['https://x.example/acme'], $owner['sameAs']);
        $this->assertSame('https://example.com/about#webpage', $page['@id']);
        $this->assertSame(['@id' => 'https://example.com/#website'], $page['isPartOf']);
        $this->assertSame('ImageObject', $page['primaryImageOfPage']['@type']);
        $this->assertSame(1200, $page['primaryImageOfPage']['width']);
        $this->assertSame(2, $crumbs['itemListElement'][1]['position']);
        $this->assertSame('https://example.com/about', $crumbs['itemListElement'][1]['item']);
        $this->assertSame('Article', $article['@type']);
        $this->assertSame(['@id' => 'https://example.com/news/x#webpage'], $article['mainEntityOfPage']);
        $this->assertSame(['@id' => 'https://example.com/#owner'], $article['author']);
        $this->assertSame('2026-11-01', $article['datePublished']);
    }

    public function test_schema_helpers_leave_out_empty_values(): void
    {
        $site = Schema::webSite('https://example.com', 'Example', 'en');
        $page = Schema::webPage('https://example.com/', 'Home', '', 'en', 'https://example.com');
        $article = Schema::article('https://example.com/x', 'X', '', 'en');

        $this->assertArrayNotHasKey('publisher', $site);
        $this->assertArrayNotHasKey('description', $page);
        $this->assertArrayNotHasKey('primaryImageOfPage', $page);
        $this->assertArrayNotHasKey('author', $article);
        $this->assertArrayNotHasKey('image', $article);
        $this->assertArrayNotHasKey('datePublished', $article);
    }
}
