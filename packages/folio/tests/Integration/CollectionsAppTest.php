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

    public function test_a_page_lists_only_records_its_language_can_open(): void
    {
        $this->write('resources/folio/frontend/post.twig', '<p class="names">{% for p in folio_list("post") %}{{ p.slug }};{% endfor %}</p>'
            . '<p class="find">{{ folio_find("post", "p1").slug|default("none") }}|{{ folio_find("post", "p2").slug|default("none") }}</p>');
        // p1 is public in English only (no _locales), p2 in English and German.
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'One'], 'slug' => ['en' => 'one']]), null);
        $this->publisher()->publish($this->working('post', 'p2', ['title' => ['en' => 'Two', 'de' => 'Zwei'], 'slug' => ['en' => 'two', 'de' => 'zwei'], '_locales' => ['en', 'de']]), null);

        $de = (string) $this->get('/de/zwei')->getBody();
        $this->assertStringContainsString('<p class="names">zwei;</p>', $de);
        // folio_find is a lookup by key: it still returns a record the German page could not open.
        $this->assertStringContainsString('<p class="find">one|zwei</p>', $de);
        $this->assertSame(404, $this->get('/de/one')->getStatusCode());

        $en = (string) $this->get('/one')->getBody();
        $this->assertStringContainsString('<p class="names">one;two;</p>', $en);
    }

    public function test_a_type_without_translatable_fields_lists_in_every_language(): void
    {
        $this->write('resources/folio/frontend/post.twig', '<p class="notes">{% for n in folio_list("note") %}{{ n.name }};{% endfor %}</p>');
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'One', 'de' => 'Eins'], 'slug' => ['en' => 'one', 'de' => 'eins'], '_locales' => ['en', 'de']]), null);
        $this->publisher()->publish($this->working('note', 'n1', ['name' => 'First']), null);

        $this->assertStringContainsString('<p class="notes">First;</p>', (string) $this->get('/one')->getBody());
        $this->assertStringContainsString('<p class="notes">First;</p>', (string) $this->get('/de/eins')->getBody());
    }
}
