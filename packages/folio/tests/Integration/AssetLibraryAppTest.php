<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\MediaLibrary;
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

    /** The asset field's drop (0.75): with the JSON Accept the upload route answers the chosen cards. */
    public function test_a_json_upload_answers_the_card_of_each_file(): void
    {
        $res = $this->uploadFiles([$this->file($this->png(), 'logo.png')], ['picker' => '1', 'multiple' => '0', 'private' => '0'], json: true);

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/json; charset=UTF-8', $res->getHeaderLine('Content-Type'));
        $this->assertSame('no-store', $res->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame([], $data['errors']);
        $this->assertCount(1, $data['items']);
        $item = $data['items'][0];
        $this->assertSame(['id', 'thumb', 'label', 'meta', 'warn', 'focal'], array_keys($item));
        $this->assertSame($this->lib()->all()[0]->id, $item['id']);
        $this->assertSame('logo.png', $item['label']);
        $this->assertSame('image · 200 × 100', $item['meta']);
        $this->assertTrue($item['warn'], 'no alt text yet');
        $this->assertSame('50,50', $item['focal']);
        $this->assertNotSame('', $item['thumb']);
    }

    public function test_a_json_upload_with_a_refused_file_answers_422_with_the_created_ones(): void
    {
        $res = $this->uploadFiles([$this->file('<?php echo 1;', 'evil.php'), $this->file($this->png(), 'ok.png')], ['picker' => '1'], json: true);

        $this->assertSame(422, $res->getStatusCode());
        $data = json_decode((string) $res->getBody(), true);
        $this->assertSame(['evil.php: files of type text/x-php are not allowed.'], $data['errors']);
        $this->assertSame(['ok.png'], array_column($data['items'], 'label'));
    }

    public function test_without_the_json_accept_the_upload_still_redirects_to_the_picker(): void
    {
        $res = $this->uploadFiles([$this->file($this->png(), 'one.png')], ['picker' => '1', 'multiple' => '0']);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('&selected=' . $this->lib()->all()[0]->id, $res->getHeaderLine('Location'));
    }

    public function test_the_picker_carries_the_same_card(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'pick.png'), null);
        $this->lib()->update($asset->id, ['alt' => ['en' => 'A logo'], 'focal_x' => 20, 'focal_y' => 80]);

        $page = (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody();
        $this->assertStringContainsString('data-label="pick.png" data-meta="image · 200 × 100" data-warn="0" data-focal="20,80"', $page);
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

        // The list view's table sits in the same bordered card as every other Folio table.
        $this->assertMatchesRegularExpression('#<div class="folio-table-wrap">\s*<table class="folio-table folio-asset-list">#', (string) $this->get('/folio/assets?view=list')->getBody());
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

    public function test_the_details_are_a_full_page_even_for_htmx(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'a.png'), null);
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id)->withHeader('HX-Request', 'true');

        $body = (string) $this->app()->handle($req)->getBody();

        $this->assertStringContainsString('<html', $body);
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

    public function test_type_named_assets_is_an_error_at_boot(): void
    {
        // {prefix}/assets is the asset library, so a type cannot take the key.
        $this->writeModel('assets', ['frontend' => ['route' => '/gallery/{slug}'], 'publishing' => false, 'fields' => ['slug' => ['type' => 'string']]]);

        $this->expectException(\Preflow\Folio\Content\FrontendConfigException::class);
        $this->expectExceptionMessage('Type "assets": the key is a path Folio uses itself');

        $this->get('/gallery/summer');
    }

    public function test_refused_field_upload_shows_in_the_form(): void
    {
        $f = new Psr17Factory();
        $res = $this->app()->handle($f->createServerRequest('POST', '/folio/post')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody(['title' => 'T', 'slug' => 'hello', '_locale' => 'en'])
            ->withUploadedFiles(['cover' => $f->createUploadedFile($f->createStream("notes\n"), 6, UPLOAD_ERR_OK, 'notes.txt')]));

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('notes.txt: this field only accepts image/*.', (string) $res->getBody());
        $this->assertSame(0, $this->dm()->queryType('post')->get()->total());
    }

    private function lib(): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    /**
     * @param list<UploadedFileInterface> $files
     * @param array<string, string>       $body
     */
    private function uploadFiles(array $files, array $body = [], bool $json = false): ResponseInterface
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/folio/assets')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withParsedBody($body)
            ->withUploadedFiles(['files' => $files]);

        return $this->app()->handle($json ? $request->withHeader('Accept', 'application/json') : $request);
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
