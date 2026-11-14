<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The library screen (0.76): the selection bar's Move to bin (POST assets/bulk) refuses what a single
 * delete refuses, "Edit alt text" for the selection (GET/POST assets/alt), and the screen itself: header,
 * toolbar, cards with "used N×", the drop zone and the bar, none of which the picker has.
 */
#[RequiresPhpExtension('gd')]
final class AssetBulkTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_bin_refuses_used_assets_and_bins_the_rest(): void
    {
        // Set up before signing in: another app on this process would start a session of its own.
        $lib = $this->lib();
        $used = $lib->upload($this->file($this->png(), 'used.png'), null);
        $free = $lib->upload($this->file($this->png(), 'free.png'), null);
        $also = $lib->upload($this->file("notes\n", 'notes.txt'), null);
        $original = $lib->upload($this->file($this->png(), 'original.png'), null);
        $lib->makeCopy($original->id, null); // an original with a live copy is in use by it
        $binned = $lib->upload($this->file($this->png(), 'binned.png'), null);
        $lib->delete($binned->id);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $used->id]);
        $this->signIn(['*']);
        $lib = $this->session->container()->get(MediaLibrary::class);

        $res = $this->send('POST', '/folio/assets/bulk', [
            '_action' => 'bin',
            'ids' => [$used->id, $free->id, $also->id, $original->id, $binned->id, str_repeat('f', 32), '../etc'],
            'back' => '/folio/assets?view=list',
        ]);

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets?view=list', $res->getHeaderLine('Location'));
        $lib->flush();
        $this->assertNull($lib->find($free->id));
        $this->assertNotNull($lib->findBinned($free->id));
        $this->assertNotNull($lib->findBinned($also->id));
        $this->assertNotNull($lib->find($used->id), 'a used asset is not binned');
        $this->assertNotNull($lib->find($original->id), 'an original with copies is not binned');
        $this->assertNotNull($lib->findBinned($binned->id), 'an asset already in the bin stays there');
        $page = (string) $this->send('GET', '/folio/assets?view=list')->getBody();
        $this->assertStringContainsString('2 files moved to the bin.', $page);
        $this->assertMatchesRegularExpression('#<div class="folio-alert folio-alert-error" role="alert">Still in use, so they stay \(remove them there first\): (used\.png, original\.png|original\.png, used\.png)</div>#', $page);
    }

    /** @return iterable<string, array{bool}> */
    public static function orders(): iterable
    {
        yield 'original first' => [true];
        yield 'copy first' => [false];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('orders')]
    public function test_an_original_and_its_copy_go_to_the_bin_together_in_any_order(bool $originalFirst): void
    {
        $lib = $this->lib();
        $original = $lib->upload($this->file($this->png(), 'original.png'), null);
        $copy = $lib->makeCopy($original->id, null);
        $this->signIn(['*']);
        $lib = $this->session->container()->get(MediaLibrary::class);

        $ids = $originalFirst ? [$original->id, $copy->id] : [$copy->id, $original->id];
        $this->assertSame(302, $this->send('POST', '/folio/assets/bulk', ['_action' => 'bin', 'ids' => $ids])->getStatusCode());

        $lib->flush();
        $this->assertNotNull($lib->findBinned($copy->id));
        $this->assertNotNull($lib->findBinned($original->id), 'the copy went first, so the original was free');
        $this->assertStringContainsString('2 files moved to the bin.', (string) $this->send('GET', '/folio/assets')->getBody());
    }

    public function test_unused_and_missing_alt_take_whitespace_alt_text_as_query_does(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin', 'cleanup' => ['enabled' => true, 'min_age_days' => 7]]);
        $blank = $this->lib()->upload($this->file($this->png(), 'blank.png'), null);
        $spaces = $this->lib()->upload($this->file($this->png(), 'spaces.png'), null);
        $dm = $this->dm();
        foreach ([$blank->id, $spaces->id] as $id) {
            $record = $dm->findIn($this->lib()->definition(), $id) ?? throw new \RuntimeException('missing ' . $id);
            $record->set('created_at', '2020-01-01T00:00:00+00:00');
            if ($id === $spaces->id) {
                $record->set('alt', ['en' => '  ']); // written around the library, which trims: one rule, exactly '' or none is missing
            }
            $dm->saveType($record, false);
        }

        foreach (['/folio/assets?missing_alt=1', '/folio/assets?missing_alt=1&unused=1'] as $url) {
            $page = (string) $this->get($url)->getBody();
            $this->assertStringContainsString('blank.png', $page, $url);
            $this->assertStringNotContainsString('spaces.png', $page, $url);
        }
    }

    public function test_an_empty_library_has_the_count_slot_and_the_drop_zone(): void
    {
        $page = (string) $this->get('/folio/assets')->getBody();
        $this->assertStringContainsString('<span class="folio-title-count folio-assets-count" data-folio-assets-count hidden>0 files</span>', $page);
        $this->assertMatchesRegularExpression('#<div class="folio-asset-drop is-alone">\s*(\{\#.*?\#\}\s*)?<label class="folio-asset-dropzone" for="folio-assets-files" data-folio-assets-drop>#s', $page);
        $this->assertStringContainsString('id="folio-assets-files"', $page, 'the input it labels');
        $this->assertStringContainsString('No assets yet.', $page);

        $picker = (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody();
        $this->assertStringNotContainsString('data-folio-assets-drop', $picker);
        $this->assertStringNotContainsString('data-folio-assets-count', $picker);
    }

    #[RunInSeparateProcess]
    public function test_bulk_needs_permission_and_csrf(): void
    {
        $this->signIn(['assets.view']);
        $lib = $this->session->container()->get(MediaLibrary::class);
        $asset = $lib->upload($this->file($this->png(), 'photo.png'), null);

        $this->assertSame(403, $this->send('POST', '/folio/assets/bulk', ['_action' => 'bin', 'ids' => [$asset->id]])->getStatusCode());
        $this->assertSame(403, $this->send('GET', '/folio/assets/alt?ids%5B%5D=' . $asset->id)->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/assets/alt', ['alt' => [$asset->id => 'x']])->getStatusCode());
        $page = (string) $this->send('GET', '/folio/assets')->getBody();
        $this->assertStringNotContainsString('name="_action" value="bin"', $page, 'Move to bin is for assets.delete');
        $this->assertStringNotContainsString('data-folio-bulk-drawer', $page, 'Edit alt text is for assets.edit');
        $this->assertStringContainsString('data-folio-bulk-one="data-download"', $page, 'Download is for every viewer');
        $this->assertNotNull($lib->find($asset->id));
    }

    #[RunInSeparateProcess]
    public function test_bulk_without_the_csrf_token_is_refused(): void
    {
        $this->signIn(['assets.view', 'assets.delete']);
        $lib = $this->session->container()->get(MediaLibrary::class);
        $asset = $lib->upload($this->file($this->png(), 'photo.png'), null);

        $req = (new Psr17Factory())->createServerRequest('POST', '/folio/assets/bulk')->withHeader('Sec-Fetch-Site', 'same-origin')
            ->withCookieParams([session_name() => session_id()])->withParsedBody(['_action' => 'bin', 'ids' => [$asset->id]]);
        $this->assertSame(403, $this->session->handle($req)->getStatusCode());
        $this->assertNotNull($lib->find($asset->id));

        $this->assertSame(302, $this->send('POST', '/folio/assets/bulk', ['_action' => 'bin', 'ids' => [$asset->id]])->getStatusCode());
        $lib->flush();
        $this->assertNotNull($lib->findBinned($asset->id));
    }

    public function test_an_unknown_bulk_action_is_a_404(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $this->assertSame(404, $this->post('/folio/assets/bulk', ['_action' => 'purge', 'ids' => [$asset->id]])->getStatusCode());
        $this->assertNotNull($this->lib()->find($asset->id));
    }

    public function test_edit_alt_text_lists_the_selected_images_and_saves_each(): void
    {
        $barn = $this->lib()->upload($this->file($this->png(), 'barn.png'), null);
        $field = $this->lib()->upload($this->file($this->png(), 'field.png'), null);
        $this->lib()->update($field->id, ['alt' => ['en' => 'A field', 'de' => 'Ein Feld']]);
        $doc = $this->lib()->upload($this->file("notes\n", 'notes.txt'), null);

        $form = (string) $this->get('/folio/assets/alt?ids%5B%5D=' . $barn->id . '&ids%5B%5D=' . $field->id . '&ids%5B%5D=' . $doc->id . '&_drawer=1')->getBody();
        $this->assertStringContainsString('class="folio-drawer-body"', $form);
        $this->assertStringContainsString('name="alt[' . $barn->id . ']" value=""', $form);
        $this->assertStringContainsString('name="alt[' . $field->id . ']" value="A field"', $form);
        $this->assertStringNotContainsString('alt[' . $doc->id . ']', $form, 'only images get an alt text');
        $this->assertStringContainsString('1 selected file is not an image and is left out.', $form);
        $this->assertStringContainsString('<input type="hidden" name="_drawer" value="1">', $form);
        // Guarded (Folio.unsaved): Escape or Close asks before typed alt texts are thrown away.
        $this->assertStringContainsString('class="folio-assets-alt-form" data-folio-guard>', $form);

        $saved = $this->post('/folio/assets/alt', ['_drawer' => '1', 'alt' => [$barn->id => ' A red barn ', $field->id => 'A green field', $doc->id => 'nope']]);
        $this->assertSame(200, $saved->getStatusCode());
        $this->assertStringContainsString('"asset-alt"', (string) $saved->getBody(), 'the drawer tells the library page to refresh');
        $this->assertSame('A red barn', $this->lib()->find($barn->id)?->alt['en']);
        $this->assertSame(['en' => 'A green field', 'de' => 'Ein Feld'], $this->lib()->find($field->id)?->alt, 'only the default language changes');
        $this->assertSame([], $this->lib()->find($doc->id)?->alt);

        $page = $this->post('/folio/assets/alt', ['back' => '/folio/assets?missing_alt=1', 'alt' => [$barn->id => '']]);
        $this->assertSame(302, $page->getStatusCode());
        $this->assertSame('/folio/assets?missing_alt=1', $page->getHeaderLine('Location'));
        $this->assertSame('', $this->lib()->find($barn->id)?->alt['en']);
    }

    public function test_the_library_screen(): void
    {
        $used = $this->lib()->upload($this->file($this->png(), 'used.png'), null);
        $this->lib()->update($used->id, ['alt' => ['en' => 'Used once']]);
        $plain = $this->lib()->upload($this->file($this->png(), 'plain.png'), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $used->id]);

        $page = (string) $this->get('/folio/assets?q=png')->getBody();
        // Header: the count, Bin and Upload (shown by admin.js; the form stays for no script).
        $this->assertMatchesRegularExpression('#<span class="folio-title-count folio-assets-count" data-folio-assets-count>2 files</span>#', $page);
        $this->assertMatchesRegularExpression('#<a class="btn btn-secondary folio-assets-bin" href="/folio/assets/bin"><svg[^>]*><use href="[^"]*\#ui-trash">#', $page);
        $this->assertStringContainsString('data-folio-assets-choose hidden>', $page);
        $this->assertStringContainsString('class="folio-assets-upload" data-folio-assets-upload>', $page);
        // Toolbar: kind as segmented links that keep the search, "Missing alt text · 1", the view toggle with names.
        $this->assertStringContainsString('<a href="/folio/assets?kind=image&amp;q=png">Images</a>', $page);
        $this->assertStringContainsString('<a href="/folio/assets?q=png" aria-current="page">All</a>', $page);
        $this->assertStringContainsString('href="/folio/assets?missing_alt=1&amp;q=png">Missing alt text · 1</a>', $page);
        $this->assertStringContainsString('<a href="/folio/assets?view=list&amp;q=png" aria-label="List" title="List">', $page);
        // Cards: the mono name, "used N×" and a muted "unused", the tick boxes of the bar, the drop zone last.
        $this->assertStringContainsString('<span class="folio-asset-filename" title="used.png">used.png</span>', $page);
        $this->assertMatchesRegularExpression('#<span class="folio-asset-meta">20×10 · 1 KB · used 1×</span>#', $page);
        $this->assertMatchesRegularExpression('#<span class="folio-asset-meta">20×10 · 1 KB · <span class="folio-asset-unused">unused</span></span>#', $page);
        $this->assertStringContainsString('name="ids[]" value="' . $plain->id . '" form="folio-bulk" data-folio-bulk-item data-label="plain.png" data-kind="image" data-download="/media/', $page);
        $this->assertMatchesRegularExpression('#<li class="folio-asset-drop">\s*<label class="folio-asset-dropzone" for="folio-assets-files" data-folio-assets-drop>#', $page);
        $this->assertStringContainsString('<form class="folio-bulk-bar folio-assets-bulk" id="folio-bulk" method="post" action="/folio/assets/bulk"', $page);
        $this->assertStringContainsString('<nav class="folio-pager"', $page);
        $this->assertSame(1, preg_match_all('#class="folio-asset-alt-warn"#', $page), 'the image without alt text is marked');

        // The filter lists the same set it counts.
        $missing = (string) $this->get('/folio/assets?missing_alt=1')->getBody();
        $this->assertStringContainsString('plain.png', $missing);
        $this->assertStringNotContainsString('used.png', $missing);
        $this->assertStringContainsString('aria-current="page">Missing alt text · 1</a>', $missing);
        $this->assertStringContainsString('data-folio-assets-count>1 file</span>', $missing);

        // The list view: the same facts, and a tick box per row.
        $list = (string) $this->get('/folio/assets?view=list')->getBody();
        $this->assertStringContainsString('<td class="folio-asset-facts">20×10 · 1 KB · used 1×</td>', $list);
        $this->assertStringContainsString('<input type="checkbox" data-folio-bulk-all', $list);

        // The picker keeps its contract and has no bar, drop zone, missing-alt toggle or usage counts.
        $picker = (string) $this->get('/folio/assets?picker=1&multiple=0')->getBody();
        $this->assertStringContainsString('data-folio-pick data-id="' . $plain->id . '"', $picker);
        foreach (['data-folio-bulk', 'folio-asset-drop', 'folio-assets-missing-alt', 'used 1×', 'data-folio-assets-upload'] as $absent) {
            $this->assertStringNotContainsString($absent, $picker, $absent);
        }
        $this->assertStringContainsString('<a href="/folio/assets?kind=image&amp;picker=1&amp;multiple=0">Images</a>', $picker);
    }

    public function test_the_missing_alt_count_follows_the_kind_and_the_search(): void
    {
        $this->lib()->upload($this->file($this->png(), 'barn.png'), null);
        $this->lib()->upload($this->file($this->png(), 'field.png'), null);

        $this->assertStringContainsString('href="/folio/assets?missing_alt=1">Missing alt text · 2</a>', (string) $this->get('/folio/assets')->getBody());
        $searched = (string) $this->get('/folio/assets?q=barn')->getBody();
        $this->assertStringContainsString('href="/folio/assets?missing_alt=1&amp;q=barn">Missing alt text · 1</a>', $searched, 'what the toggle would list');
        $this->assertStringContainsString('aria-current="page">Missing alt text · 1</a>', (string) $this->get('/folio/assets?missing_alt=1&q=barn')->getBody());
        $this->assertStringNotContainsString('folio-assets-missing-alt', (string) $this->get('/folio/assets?kind=document')->getBody(), 'no image of that kind');
    }

    public function test_the_missing_alt_toggle_hides_when_every_image_has_one(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $this->lib()->update($asset->id, ['alt' => ['en' => 'A photo']]);

        $this->assertStringNotContainsString('folio-assets-missing-alt', (string) $this->get('/folio/assets')->getBody());
        $on = (string) $this->get('/folio/assets?missing_alt=1')->getBody();
        $this->assertStringContainsString('Missing alt text · 0', $on, 'shown while it filters');
        $this->assertStringContainsString('Every image has an alt text.', $on);
    }

    /** @param list<string> $permissions the admin role's permissions */
    private function signIn(array $permissions): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'folio_asset_bulk_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => ['admin' => $permissions]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method !== 'GET') {
            // As a browser posts a form of the site: same origin, with the session cookie and the token.
            $req = $req->withHeader('Sec-Fetch-Site', 'same-origin')->withCookieParams([session_name() => session_id()])
                ->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }

    /** @param array<string, mixed> $config */
    private function media(array $config): void
    {
        $this->write('config/media.php', "<?php\nreturn " . var_export($config, true) . ";\n");
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

    private function png(): string
    {
        $image = imagecreatetruecolor(20, 10);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
