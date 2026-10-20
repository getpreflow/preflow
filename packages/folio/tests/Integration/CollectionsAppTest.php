<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/** folio_list/folio_find and the query variable in frontend templates. */
final class CollectionsAppTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('resources/folio/frontend/post.twig', '<p class="count">{{ folio_list("note")|length }}</p>'
            . '<p class="names">{% for n in folio_list("note") %}{{ n.name }};{% endfor %}</p>'
            . '<p class="find">{{ folio_find("note", "n1").name|default("none") }}</p>'
            . '<p class="q">{{ query.x|default("-") }}</p>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_templates_list_published_records_and_see_the_query(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]), null);
        $this->publisher()->publish($this->working('note', 'n1', ['name' => 'First']), null);
        $this->working('note', 'n2', ['name' => 'Draft']);

        $html = (string) $this->get('/hello?x=7')->getBody();

        $this->assertStringContainsString('<p class="count">1</p>', $html);
        $this->assertStringContainsString('<p class="names">First;</p>', $html);
        $this->assertStringContainsString('<p class="find">First</p>', $html);
        $this->assertStringContainsString('<p class="q">7</p>', $html);
    }

    public function test_query_defaults_to_empty(): void
    {
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello']]), null);

        $this->assertStringContainsString('<p class="q">-</p>', (string) $this->get('/hello')->getBody());
    }
}
