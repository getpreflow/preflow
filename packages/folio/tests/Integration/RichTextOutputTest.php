<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * Rich text reaches templates sanitized however it was stored (admin save, import,
 * app code): in blocks, nested data-only blocks, folio_list, folio_find and folio_get.
 * folio_asset_url() gives a library asset's URL.
 */
final class RichTextOutputTest extends TestCase
{
    use PublishingScaffold;

    private const string EVIL = '<p onclick="steal()">kept</p><script>steal()</script><img src="x" onerror="steal()">';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\ProseBlock\\ProseBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class]]];\n");
        $this->writeModel('snippet', [
            'label' => 'Snippets',
            'publishing' => true,
            'fields' => ['name' => ['type' => 'string'], 'body' => ['type' => 'richtext', 'translatable' => true]],
        ]);
        $this->writeModel('site', [
            'label' => 'Site',
            'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string', 'translatable' => true], 'footer' => ['type' => 'richtext', 'translatable' => true]],
        ]);
        $this->write('resources/folio/frontend/post.twig', '<html><body><main>{{ folio_blocks(record.sections|default([])) }}</main>'
            . '<div class="list">{% for s in folio_list("snippet") %}{{ s.body|raw }}{% endfor %}</div>'
            . '<div class="find">{{ folio_find("snippet", "s1").body|default("")|raw }}</div>'
            . '<div class="site">{{ folio_get("site").footer|default("")|raw }}</div>'
            . '<a class="file" href="{{ folio_asset_url(record.cover|default(null)) }}">file</a><a class="none" href="{{ folio_asset_url("nope") }}">none</a></body></html>');
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_stored_rich_text_is_sanitized_wherever_templates_read_it(): void
    {
        $this->publisher()->publish($this->working('snippet', 's1', ['name' => 'S', 'body' => ['en' => self::EVIL]]), null);
        $this->publisher()->publish($this->working('site', 'site', ['tagline' => ['en' => 'T'], 'footer' => ['en' => self::EVIL]]), null);
        $this->publisher()->publish($this->working('post', 'p1', [
            'title' => ['en' => 'T'],
            'slug' => ['en' => 'hello'],
            'sections' => [[
                '_uid' => 'b1', '_block' => 'prose',
                'body' => ['en' => self::EVIL],
                'caption' => ['en' => '<b>plain</b>'],
                'quotes' => [['_uid' => 'q1', '_block' => 'quote', 'text' => ['en' => self::EVIL]]],
            ]],
        ]), null);

        $html = (string) $this->get('/hello')->getBody();

        preg_match('/(Key|Variable|Unknown|Impossible)[^<]{0,200}/', $html, $error);
        $this->assertSame(5, substr_count($html, 'kept'), 'block, nested block, list, find and singleton all render' . ($error ? ': ' . html_entity_decode($error[0]) : ''));
        $this->assertStringNotContainsString('steal()', $html);
        $this->assertStringContainsString('&lt;b&gt;plain&lt;/b&gt;', $html, 'plain strings stay escaped, not sanitized HTML');
    }

    public function test_folio_asset_url_gives_the_library_url(): void
    {
        $file = $this->dir . '/paper.pdf';
        file_put_contents($file, "%PDF-1.4\n%%EOF\n");
        $asset = $this->app()->container()->get(AssetLibrary::class)->importFile($file, 'paper.pdf', null);
        $this->publisher()->publish($this->working('post', 'p1', ['title' => ['en' => 'T'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);

        $html = (string) $this->get('/hello')->getBody();

        preg_match('/<a class="file" href="([^"]*)"/', $html, $file);
        $this->assertStringStartsWith('/media/', $file[1] ?? '');
        $this->assertStringEndsWith('.pdf', $file[1] ?? '');
        $this->assertStringContainsString('<a class="none" href="">', $html);
    }
}
