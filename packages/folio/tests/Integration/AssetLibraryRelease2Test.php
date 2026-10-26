<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\StoredAssets;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The library's media 0.35 parts, through the real app: replace and the version list
 * (restore, delete), the extra fields of config/media.php in the panel and as toolbar
 * filters, PDF previews as thumbnails and colour swatches.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class AssetLibraryRelease2Test extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/media.php', "<?php\nreturn " . var_export([
            'fields' => [
                'usage_type' => ['type' => 'select', 'label' => 'Usage', 'options' => ['cover' => 'Cover', 'step' => 'Step']],
                'game' => ['type' => 'relation', 'label' => 'Game', 'relation' => ['to' => 'post', 'labelField' => 'title']],
                'featured' => ['type' => 'boolean', 'label' => 'Featured'],
            ],
            'filters' => ['usage_type', 'game', 'featured'],
        ], true) . ";\n");
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);
        $this->working('post', 'p2', ['title' => ['en' => 'World'], 'slug' => ['en' => 'world']]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_replace_stays_on_the_panel_and_shows_the_new_file(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);

        $res = $this->replace($asset->id, $this->file($this->png(300, 150), 'new.png'));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '?done=replaced', $res->getHeaderLine('Location'));
        $panel = (string) $this->get($res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('Replaced.', $panel);
        $this->assertStringContainsString('300×150', $panel);
        $this->assertStringContainsString($asset->id . '-v2', $panel);
        $this->assertSame(2, $this->lib()->find($asset->id)?->version);
    }

    public function test_replace_with_another_kind_shows_the_error_in_the_panel(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);

        $res = $this->replace($asset->id, $this->file("notes\n", 'notes.txt'));

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('folio-asset-panel', $body);
        $this->assertStringContainsString('role="alert"', $body);
        $this->assertSame(1, $this->lib()->find($asset->id)?->version);
    }

    public function test_replace_without_a_file_says_so(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);

        $res = $this->post('/folio/assets/' . $asset->id . '/replace');

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Choose a file', (string) $res->getBody());
    }

    public function test_the_version_list_shows_earlier_files_with_date_size_and_link(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);
        $this->lib()->replace($asset->id, $this->file($this->png(300, 150), 'new.png'), null);
        $v1 = $this->lib()->versions($asset->id)[0];

        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();

        $this->assertStringContainsString('folio-asset-versions', $panel);
        $this->assertMatchesRegularExpression('#<li class="folio-asset-version">\s*<span class="folio-asset-version-number">v1</span>#', $panel);
        $this->assertStringContainsString((new \DateTimeImmutable($v1->createdAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('M j, Y H:i'), $panel); // folio_date(…, 'datetime'), English
        $this->assertStringContainsString((int) ceil($v1->size / 1024) . ' KB · 200×100', $panel);
        $this->assertStringContainsString('href="/files/' . $asset->id . '/v/1"', $panel);
        $this->assertStringContainsString('action="/folio/assets/' . $asset->id . '/versions/1/restore"', $panel);
        $this->assertStringContainsString('action="/folio/assets/' . $asset->id . '/versions/1/delete"', $panel);
    }

    public function test_restore_and_delete_a_version(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);
        $this->lib()->replace($asset->id, $this->file($this->png(300, 150), 'new.png'), null);

        $res = $this->post('/folio/assets/' . $asset->id . '/versions/1/restore');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '?done=restored&version=1', $res->getHeaderLine('Location'));
        $restored = $this->lib()->find($asset->id);
        $this->assertSame([3, 200], [$restored?->version, $restored?->width]);
        $this->assertSame([2, 1], array_map(static fn ($v) => $v->number, $this->lib()->versions($asset->id)));
        $this->assertStringContainsString('Restored version 1.', (string) $this->get($res->getHeaderLine('Location'))->getBody());

        $res = $this->post('/folio/assets/' . $asset->id . '/versions/2/delete');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '?done=version-deleted', $res->getHeaderLine('Location'));
        $this->assertSame([1], array_map(static fn ($v) => $v->number, $this->lib()->versions($asset->id)));
        $this->assertStringContainsString('Version deleted.', (string) $this->get($res->getHeaderLine('Location'))->getBody());
    }

    public function test_restore_or_delete_of_an_unknown_version_is_404(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);

        $this->assertSame(404, $this->post('/folio/assets/' . $asset->id . '/versions/7/restore')->getStatusCode());
        $this->assertSame(404, $this->post('/folio/assets/' . $asset->id . '/versions/7/delete')->getStatusCode());
        $this->assertSame(404, $this->post('/folio/assets/' . str_repeat('a', 32) . '/replace')->getStatusCode());
    }

    public function test_a_failure_in_the_core_is_a_panel_error_not_a_500(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'old.png'), null);
        $this->lib()->replace($asset->id, $this->file($this->png(300, 150), 'new.png'), null);
        $records = $this->dir . '/storage/data/folio_assets';
        $log = ini_set('error_log', $this->dir . '/php-error.log');
        chmod($records, 0555); // the record can't be saved
        set_error_handler(static fn (): bool => true); // the driver's own write warning
        try {
            $res = $this->post('/folio/assets/' . $asset->id . '/versions/1/delete');
        } finally {
            restore_error_handler();
            chmod($records, 0775);
            ini_set('error_log', (string) $log);
        }

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('folio-asset-panel', $body);
        $this->assertStringContainsString('Could not delete version 1. The error was logged.', $body);
        $this->assertStringContainsString('could not delete version 1 of asset ' . $asset->id, (string) file_get_contents($this->dir . '/php-error.log'));
        $this->assertSame([1], array_map(static fn ($v) => $v->number, $this->lib()->versions($asset->id)));
    }

    public function test_extra_fields_render_with_folio_field_types_and_save(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'a.png'), null);

        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();
        $this->assertMatchesRegularExpression('#<select[^>]*name="usage_type"#', $panel);
        $this->assertStringContainsString('<option value="cover">Cover</option>', $panel);
        $this->assertMatchesRegularExpression('#<div class="form-group folio-relation">\s*<label for="game">Game</label>\s*<select name="game"#', $panel);
        $this->assertStringContainsString('<option value="p1">Hello</option>', $panel);
        $this->assertMatchesRegularExpression('#<input type="checkbox"[^>]*name="featured"#', $panel);

        $res = $this->post('/folio/assets/' . $asset->id, ['_extra' => '1', 'alt' => ['en' => 'Sea'], 'usage_type' => 'cover', 'game' => 'p1', 'featured' => '1']);

        $this->assertSame(302, $res->getStatusCode());
        $saved = $this->lib()->find($asset->id);
        $this->assertSame(['usage_type' => 'cover', 'game' => 'p1', 'featured' => true], $saved?->extra);
        $this->assertSame(['en' => 'Sea'], $saved?->alt);
        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();
        $this->assertStringContainsString('<option value="p1" selected>Hello</option>', $panel);

        // Unchecking the box saves false (the field type's hidden "0").
        $this->post('/folio/assets/' . $asset->id, ['_extra' => '1', 'usage_type' => 'cover', 'game' => 'p1', 'featured' => '0']);
        $this->assertFalse($this->lib()->find($asset->id)?->extra['featured']);
    }

    public function test_the_toolbar_filters_by_the_configured_filters(): void
    {
        $cover = $this->lib()->upload($this->file($this->png(200, 100), 'cover-one.png'), null);
        $this->lib()->update($cover->id, ['usage_type' => 'cover', 'game' => 'p1']);
        $step = $this->lib()->upload($this->file($this->png(200, 100), 'step-two.png'), null);
        $this->lib()->update($step->id, ['usage_type' => 'step', 'game' => 'p2', 'featured' => true]);

        $index = (string) $this->get('/folio/assets')->getBody();
        $this->assertMatchesRegularExpression('#<select name="f\[usage_type\]" aria-label="Usage">#', $index);
        $this->assertStringContainsString('<option value="cover">Cover</option>', $index);
        $this->assertMatchesRegularExpression('#<select name="f\[game\]" aria-label="Game">#', $index);
        $this->assertStringContainsString('<option value="p2">World</option>', $index);
        $this->assertMatchesRegularExpression('#<select name="f\[featured\]" aria-label="Featured">#', $index);

        $byUsage = (string) $this->get('/folio/assets?f%5Busage_type%5D=cover')->getBody();
        $this->assertStringContainsString('cover-one.png', $byUsage);
        $this->assertStringNotContainsString('step-two.png', $byUsage);
        $this->assertStringContainsString('<option value="cover" selected>Cover</option>', $byUsage);

        $byGame = (string) $this->get('/folio/assets?f%5Bgame%5D=p2&view=list')->getBody();
        $this->assertStringContainsString('step-two.png', $byGame);
        $this->assertStringNotContainsString('cover-one.png', $byGame);
        // The view links keep the filter.
        $this->assertStringContainsString('f%5Bgame%5D=p2', $byGame);

        $featured = (string) $this->get('/folio/assets?f%5Bfeatured%5D=1')->getBody();
        $this->assertStringContainsString('step-two.png', $featured);
        $this->assertStringNotContainsString('cover-one.png', $featured);
        $this->assertStringContainsString('No assets match.', (string) $this->get('/folio/assets?f%5Busage_type%5D=step&f%5Bgame%5D=p1')->getBody());
    }

    public function test_a_pdf_with_a_preview_uses_it_as_thumbnail_in_grid_picker_and_panel(): void
    {
        $pdf = $this->lib()->upload($this->file("%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'rules.pdf'), null);
        $pdf = $this->withPreview($pdf);
        $thumb = '/media/' . $pdf->preview?->path;

        $this->assertStringContainsString('<img src="' . $thumb . '"', (string) $this->get('/folio/assets')->getBody());
        $this->assertStringContainsString('data-thumb="' . $thumb . '"', (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody());
        $panel = (string) $this->get('/folio/assets/' . $pdf->id)->getBody();
        $this->assertStringContainsString('class="folio-asset-preview-image" src="' . $thumb . '"', $panel);
        $this->assertStringContainsString('href="/media/' . $pdf->path . '"', $panel); // the file itself
    }

    public function test_swatches_copy_hex_values_without_inline_styles(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'a.png'), null);
        $this->patch($asset, ['color' => '#1976d2', 'palette' => ['#1976d2', '#ffc107', '#0d47a1'], 'text_color' => '#ffffff']);

        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();

        $this->assertStringContainsString('folio-swatches', $panel);
        foreach (['#1976d2', '#ffc107', '#0d47a1'] as $hex) {
            $this->assertStringContainsString('data-copy="' . $hex . '"', $panel);
        }
        $this->assertStringContainsString('data-copy="rgb(25, 118, 210)"', $panel);
        $this->assertDoesNotMatchRegularExpression('/<[a-z][^>]*\sstyle\s*=/i', $panel);

        // Without colours: no swatches.
        $plain = $this->lib()->upload($this->file($this->png(10, 10), 'b.png'), null);
        $this->patch($plain, ['color' => '', 'palette' => [], 'text_color' => '']);
        $this->assertStringNotContainsString('folio-swatches', (string) $this->get('/folio/assets/' . $plain->id)->getBody());
    }

    public function test_the_note_shows_in_the_panel(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(200, 100), 'a.png'), null);
        $this->patch($asset, ['note' => MediaLibrary::NOTE_NEEDS_IMAGICK]);

        $this->assertStringContainsString('needs Imagick for colour-accurate variants', (string) $this->get('/folio/assets/' . $asset->id)->getBody());
    }

    /** @param array<string, mixed> $changes */
    private function patch(Asset $asset, array $changes): Asset
    {
        return StoredAssets::patch($this->dm(), $this->lib(), $asset, $changes);
    }

    private function lib(): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    /** A first-page preview as the core records it ({stem}-preview.png beside the file). */
    private function withPreview(Asset $pdf): Asset
    {
        $path = substr($pdf->path, 0, (int) strrpos($pdf->path, '.')) . '-preview.png';
        file_put_contents($this->lib()->absolute($path), $this->png(120, 160));
        return $this->patch($pdf, ['variants' => [], 'preview' => ['path' => $path, 'width' => 120, 'height' => 160]]);
    }

    private function replace(string $id, UploadedFileInterface $file): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $id . '/replace')
            ->withUploadedFiles(['file' => $file]));
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    private function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 30, 120, 200));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
