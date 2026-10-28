<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Copies in the library: an original with copies is one tile with a bubble (a search shows
 * the copies on their own), the asset page's Copies panel and a copy's link back to its
 * original, "Make a copy and edit it" on the Edit image screen, Remake from original, and
 * the picker opening a stack.
 */
#[RequiresPhpExtension('gd')]
final class AssetCopiesTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_library_shows_stack_with_count(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $a = $this->lib()->makeCopy($original->id, null);
        $b = $this->lib()->makeCopy($original->id, null);

        $page = (string) $this->get('/folio/assets')->getBody();

        $this->assertStringContainsString('data-asset-id="' . $original->id . '"', $page);
        $this->assertStringNotContainsString('data-asset-id="' . $a->id . '"', $page);
        $this->assertStringNotContainsString('data-asset-id="' . $b->id . '"', $page);
        $this->assertStringContainsString('<span class="folio-asset-stack" title="2 copies">2</span>', $page);
        $this->assertStringContainsString('1 file', $page);

        $list = (string) $this->get('/folio/assets?view=list')->getBody();
        $this->assertStringContainsString('<span class="folio-asset-stack" title="2 copies">2</span>', $list);
    }

    public function test_search_shows_copy_tiles(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $copy = $this->lib()->makeCopy($original->id, null);

        $page = (string) $this->get('/folio/assets?q=photo')->getBody();

        $this->assertStringContainsString('data-asset-id="' . $original->id . '"', $page);
        $this->assertStringContainsString('data-asset-id="' . $copy->id . '"', $page);
    }

    public function test_copies_panel_lists_copies_with_original_changed_mark(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $old = $this->lib()->makeCopy($original->id, null);
        $this->lib()->replace($original->id, $this->file($this->png(30, 15), 'photo.png'), null);
        $fresh = $this->lib()->makeCopy($original->id, null);

        $panel = (string) $this->get('/folio/assets/' . $original->id)->getBody();

        $this->assertStringContainsString('id="copies"', $panel);
        $this->assertStringContainsString('href="/folio/assets/' . $old->id . '"', $panel);
        $this->assertStringContainsString('href="/folio/assets/' . $fresh->id . '"', $panel);
        $this->assertSame(1, substr_count($panel, 'class="folio-asset-stale"'));
        $this->assertMatchesRegularExpression('/data-copy-id="' . $old->id . '".*?class="folio-asset-stale"/s', $panel);
        $this->assertStringContainsString('plain copy', $panel);
        $this->assertStringContainsString('Not used anywhere.', $panel);
        // Copies are counted apart from records: the Copies panel lists them.
        $this->assertStringContainsString('<a href="#copies">Has 2 copies (listed under Copies).</a>', $panel);
        $this->assertStringNotContainsString('Used in', $panel);

        $copyPage = (string) $this->get('/folio/assets/' . $old->id)->getBody();
        $this->assertStringContainsString('Copy of <a href="/folio/assets/' . $original->id . '">photo.png</a>', $copyPage);
        $this->assertStringContainsString('The original changed after this copy was made.', $copyPage);
        $this->assertStringNotContainsString('id="copies"', $copyPage);
        $this->assertStringNotContainsString('The original changed', (string) $this->get('/folio/assets/' . $fresh->id)->getBody());
    }

    public function test_make_copy_redirects_to_copy_editor(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $this->lib()->rotate($original->id, 1);
        $before = $this->lib()->find($original->id);
        $this->assertStringContainsString('action="/folio/assets/' . $original->id . '/copy"', (string) $this->get('/folio/assets/' . $original->id . '/edit')->getBody());

        $res = $this->post('/folio/assets/' . $original->id . '/copy');

        $copies = $this->lib()->copies($original->id);
        $this->assertCount(1, $copies);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $copies[0]->id . '/edit?done=copied', $res->getHeaderLine('Location'));
        $this->assertSame(MediaLibrary::MAKER_COPY, $copies[0]->maker);
        $this->assertSame(1, $copies[0]->rotation);
        $this->assertEquals($before, $this->lib()->find($original->id));
        $editor = (string) $this->get($res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('Copy made. Your edits now change only the copy.', $editor);

        // On a copy it makes a sibling.
        $this->post('/folio/assets/' . $copies[0]->id . '/copy');
        $this->assertCount(2, $this->lib()->copies($original->id));
    }

    public function test_make_copy_of_a_file_that_is_no_editable_image_is_a_404(): void
    {
        $doc = $this->lib()->upload($this->file('%PDF-1.4 x', 'paper.pdf'), null);

        $this->assertSame(404, $this->post('/folio/assets/' . $doc->id . '/copy')->getStatusCode());
        $this->assertSame(404, $this->post('/folio/assets/nope/copy')->getStatusCode());
        $this->assertSame([], $this->lib()->copies($doc->id));
    }

    public function test_remake_button_only_with_known_maker(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $plain = $this->lib()->makeCopy($original->id, null);
        $tmp = $this->dir . '/stamped.png';
        file_put_contents($tmp, $this->png());
        $stamped = $this->lib()->derive($original->id, $tmp, null, 'watermark', ['text' => '© Someone']);

        $this->assertStringContainsString('action="/folio/assets/' . $plain->id . '/remake"', (string) $this->get('/folio/assets/' . $plain->id)->getBody());
        $page = (string) $this->get('/folio/assets/' . $stamped->id)->getBody();
        $this->assertStringNotContainsString('/remake"', $page);
        $this->assertStringContainsString('watermark', $page);
        $this->assertStringNotContainsString('/remake"', (string) $this->get('/folio/assets/' . $original->id)->getBody());

        $res = $this->post('/folio/assets/' . $stamped->id . '/remake');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('This copy cannot be made again from its original.', (string) $res->getBody());
        $this->assertSame(404, $this->post('/folio/assets/' . $original->id . '/remake')->getStatusCode());
    }

    public function test_remake_post_keeps_id(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $copy = $this->lib()->makeCopy($original->id, null);
        $this->lib()->replace($original->id, $this->file($this->png(30, 15), 'photo.png'), null);
        $this->assertTrue($this->lib()->isStale($this->lib()->find($copy->id)));

        $res = $this->post('/folio/assets/' . $copy->id . '/remake?back=' . rawurlencode('/folio/assets?view=list'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $copy->id . '?done=remade&back=' . rawurlencode('/folio/assets?view=list'), $res->getHeaderLine('Location'));
        $remade = $this->lib()->find($copy->id);
        $this->assertNotNull($remade);
        $this->assertSame(30, $remade->width);
        $this->assertFalse($this->lib()->isStale($remade));
        $this->assertCount(1, $this->lib()->versions($copy->id));
        $this->assertCount(1, $this->lib()->copies($original->id));
        $this->assertStringContainsString('Made again from the original.', (string) $this->get($res->getHeaderLine('Location'))->getBody());
    }

    public function test_picker_stack_offers_original_and_copies(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $a = $this->lib()->makeCopy($original->id, null);
        $b = $this->lib()->makeCopy($original->id, null);

        $picker = (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody();
        $this->assertStringNotContainsString('data-id="' . $a->id . '"', $picker);
        $this->assertStringContainsString('href="/folio/assets?picker=1&amp;multiple=0&amp;stack=' . $original->id . '"', $picker);

        $stack = (string) $this->get('/folio/assets?picker=1&multiple=0&stack=' . $original->id)->getBody();
        foreach ([$original, $a, $b] as $asset) {
            $this->assertStringContainsString('data-folio-pick data-id="' . $asset->id . '"', $stack);
        }
        $this->assertStringContainsString('3 files', $stack);
        $this->assertStringContainsString('href="/folio/assets?picker=1&amp;multiple=0"', $stack); // back to every file
        $this->assertStringNotContainsString('class="folio-asset-stack"', $stack);

        // A field that takes only private files: the public stack offers nothing.
        $this->assertStringNotContainsString('data-folio-pick', (string) $this->get('/folio/assets?picker=1&multiple=0&private=1&stack=' . $original->id)->getBody());
    }

    public function test_original_usage_counts_records_apart_from_copies(): void
    {
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $this->lib()->makeCopy($original->id, null);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $original->id]);

        $panel = (string) $this->get('/folio/assets/' . $original->id)->getBody();
        $this->assertStringContainsString('<p>Used in 1 record:</p>', $panel);
        $this->assertStringContainsString('<li><a href="/folio/post/p1/edit">Hello</a></li>', $panel);
        $this->assertStringContainsString('Has 1 copy (listed under Copies).', $panel);

        $res = $this->post('/folio/assets/' . $original->id . '/delete');
        $this->assertSame(409, $res->getStatusCode());
        $this->assertStringContainsString('Used in 1 record — remove it there first. The file has 1 copy — delete the copy first.', (string) $res->getBody());
    }

    public function test_copies_are_marked_as_copies(): void
    {
        $this->write('config/media.php', "<?php\nreturn ['bin_dir' => " . var_export($this->dir . '/storage/media-bin', true) . "];\n");
        $original = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $copy = $this->lib()->makeCopy($original->id, null);
        $mark = '<span class="folio-asset-copy-mark">copy</span>';

        $library = (string) $this->get('/folio/assets')->getBody();
        $this->assertStringNotContainsString($mark, $library, 'the stack shows its original only');
        $search = (string) $this->get('/folio/assets?q=photo')->getBody();
        $this->assertSame(1, substr_count($search, $mark), 'the copy tile, not the original');
        $this->assertMatchesRegularExpression('/data-asset-id="' . $copy->id . '".*?folio-asset-copy-mark/s', $search);
        $this->assertSame(1, substr_count((string) $this->get('/folio/assets?q=photo&view=list')->getBody(), $mark));
        $stack = (string) $this->get('/folio/assets?picker=1&stack=' . $original->id)->getBody();
        $this->assertSame(1, substr_count($stack, $mark));

        $editor = (string) $this->get('/folio/assets/' . $copy->id . '/edit')->getBody();
        $this->assertStringContainsString('<span class="folio-asset-copy-mark">Copy of photo.png</span>', $editor);
        $this->assertStringNotContainsString('folio-asset-copy-mark', (string) $this->get('/folio/assets/' . $original->id . '/edit')->getBody());

        $this->lib()->delete($copy->id);
        $bin = (string) $this->get('/folio/assets/bin')->getBody();
        $this->assertStringContainsString('<span class="folio-asset-copy-mark">Copy of photo.png</span>', $bin);
    }

    private function lib(): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    private function png(int $width = 20, int $height = 10): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
