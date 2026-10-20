<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Assets\Asset;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

#[RequiresPhpExtension('gd')]
final class AssetLibraryAppTest extends TestCase
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

    public function test_multi_upload_lists_assets(): void
    {
        $res = $this->uploadFiles([$this->file($this->png(), 'one.png'), $this->file($this->png(), 'two.png')]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets', $res->getHeaderLine('Location'));
        $index = (string) $this->get('/folio/assets')->getBody();
        $this->assertSame(2, substr_count($index, 'data-asset-id="'));
        $this->assertStringContainsString('one.png', $index);
        $this->assertStringContainsString('href="/folio/assets"', $index); // nav link
    }

    public function test_failed_upload_lists_errors(): void
    {
        $res = $this->uploadFiles([$this->file('<?php echo 1;', 'evil.php'), $this->file($this->png(), 'ok.png')]);

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('evil.php: files of type text/x-php are not allowed.', $body);
        $this->assertSame(1, count($this->lib()->all()));
    }

    public function test_search_and_kind_filter(): void
    {
        $sea = $this->lib()->upload($this->file($this->png(), 'IMG_001.png'), null);
        $this->lib()->update($sea->id, ['alt' => ['en' => 'Coast', 'de' => 'Blaue See']]);
        $this->lib()->upload($this->file($this->png(), 'IMG_002.png'), null);
        $this->lib()->upload($this->file("notes\n", 'Readme.txt'), null);

        $search = (string) $this->get('/folio/assets?q=SEE')->getBody();
        $this->assertStringContainsString('IMG_001.png', $search);
        $this->assertStringNotContainsString('IMG_002.png', $search);

        $docs = (string) $this->get('/folio/assets?kind=document')->getBody();
        $this->assertStringContainsString('Readme.txt', $docs);
        $this->assertStringNotContainsString('IMG_001.png', $docs);

        $this->assertStringContainsString('folio-asset-list', (string) $this->get('/folio/assets?view=list')->getBody());
    }

    public function test_panel_saves_metadata(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'a.png'), null);

        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();
        $this->assertStringContainsString('name="alt[de]"', $panel);
        $this->assertStringContainsString('data-folio-focal', $panel);

        $res = $this->post('/folio/assets/' . $asset->id, ['alt' => ['en' => 'Sea', 'de' => 'Meer'], 'title' => 'Coast', 'focal_x' => '30', 'focal_y' => '70']);

        $this->assertSame(302, $res->getStatusCode());
        $saved = $this->lib()->find($asset->id);
        $this->assertSame(['en' => 'Sea', 'de' => 'Meer'], $saved?->alt);
        $this->assertSame('Coast', $saved?->title);
        $this->assertSame([30, 70], [$saved?->focalX, $saved?->focalY]);
    }

    public function test_panel_fragment_for_htmx(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'a.png'), null);
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id)->withHeader('HX-Request', 'true');

        $body = (string) $this->app()->handle($req)->getBody();

        $this->assertStringNotContainsString('<html', $body);
        $this->assertStringContainsString('folio-asset-panel', $body);
    }

    public function test_delete_used_asset_is_refused(): void
    {
        $used = $this->lib()->upload($this->file($this->png(), 'used.png'), null);
        $free = $this->lib()->upload($this->file($this->png(), 'free.png'), null);
        $this->working('post', 'p1', ['title' => ['en' => 'About'], 'slug' => ['en' => 'about'], 'cover' => $used->id]);

        $res = $this->post('/folio/assets/' . $used->id . '/delete');
        $this->assertSame(409, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('Used in 1 record', $body);
        $this->assertStringContainsString('About', $body);
        $this->assertNotNull($this->lib()->find($used->id));

        $res = $this->post('/folio/assets/' . $free->id . '/delete');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNull($this->lib()->find($free->id));
    }

    public function test_picker_mode_uses_drawer_layout_and_pick_buttons(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'a.png'), null);

        $body = (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody();

        $this->assertStringContainsString('folio-drawer-body', $body);
        $this->assertStringContainsString('data-folio-pick', $body);
        $this->assertStringContainsString('data-id="' . $asset->id . '"', $body);
        $this->assertStringContainsString('name="picker" value="1"', $body);

        $res = $this->uploadFiles([$this->file($this->png(), 'new.png')], ['picker' => '1', 'multiple' => '0']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('/folio/assets?picker=1&multiple=0&selected=', $res->getHeaderLine('Location'));
    }

    public function test_unknown_asset_is_404(): void
    {
        $this->assertSame(404, $this->get('/folio/assets/' . str_repeat('a', 32))->getStatusCode());
        $this->assertSame(404, $this->post('/folio/assets/' . str_repeat('a', 32) . '/delete')->getStatusCode());
    }

    public function test_type_named_assets_is_a_boot_error(): void
    {
        $this->writeModel('assets', ['fields' => ['title' => ['type' => 'string']]]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('conflicts with the asset library');
        $this->app();
    }

    private function lib(): AssetLibrary
    {
        return $this->app()->container()->get(AssetLibrary::class);
    }

    /**
     * @param list<UploadedFileInterface> $files
     * @param array<string, string>       $body
     */
    private function uploadFiles(array $files, array $body = []): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets')
            ->withParsedBody($body)
            ->withUploadedFiles(['files' => $files]));
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(200, 100);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
