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
use Psr\Http\Message\UploadedFileInterface;

/**
 * The library's bin: delete moves a file into it (with bin_dir), the bin page lists and
 * restores (assets.delete), the "Unused" filter (cleanup on), the warning without bin_dir,
 * earlier revisions as usages that don't block a delete, and records that point at a
 * binned file.
 */
#[RequiresPhpExtension('gd')]
final class AssetBinTest extends TestCase
{
    use PublishingScaffold;

    private const string NO_BIN = 'Deleted files are removed for good: set bin_dir in config/media.php.';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_delete_bins_the_file_and_says_so(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $res = $this->post('/folio/assets/' . $asset->id . '/delete');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets?done=binned', $res->getHeaderLine('Location'));
        $this->assertNull($this->lib()->find($asset->id));
        $this->assertNotNull($this->lib()->findBinned($asset->id));
        $page = (string) $this->get($res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('<p class="folio-saved" role="status">Moved to the bin.', $page);
        $this->assertStringContainsString('href="/folio/assets/bin"', $page);
        $this->assertStringNotContainsString(self::NO_BIN, $page);
    }

    public function test_delete_keeps_the_library_page_it_came_from(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $res = $this->post('/folio/assets/' . $asset->id . '/delete?back=' . rawurlencode('/folio/assets?view=list'));

        $this->assertSame('/folio/assets?view=list&done=binned', $res->getHeaderLine('Location'));
    }

    public function test_bin_page_lists_and_restore_puts_it_back(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $this->lib()->delete($asset->id);

        $bin = $this->get('/folio/assets/bin');
        $this->assertSame(200, $bin->getStatusCode());
        $body = (string) $bin->getBody();
        $this->assertStringContainsString('photo.png', $body);
        $this->assertStringContainsString('action="/folio/assets/bin/' . $asset->id . '/restore"', $body);

        $res = $this->post('/folio/assets/bin/' . $asset->id . '/restore');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '?done=unbinned', $res->getHeaderLine('Location'));
        $this->assertNotNull($this->lib()->find($asset->id));
        $this->assertStringContainsString('Restored from the bin.', (string) $this->get($res->getHeaderLine('Location'))->getBody());
        $this->assertStringContainsString('The bin is empty.', (string) $this->get('/folio/assets/bin')->getBody());
    }

    public function test_restore_of_a_file_not_in_the_bin_is_a_404(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $this->assertSame(404, $this->post('/folio/assets/bin/' . $asset->id . '/restore')->getStatusCode());
    }

    public function test_without_bin_dir_the_library_warns_and_delete_says_permanently(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $index = (string) $this->get('/folio/assets')->getBody();
        $this->assertStringContainsString(self::NO_BIN, $index);
        $this->assertSame(1, substr_count($index, self::NO_BIN));
        $this->assertStringNotContainsString('href="/folio/assets/bin"', $index);
        $this->assertStringContainsString('data-confirm="Delete this file permanently?"', (string) $this->get('/folio/assets/' . $asset->id)->getBody());

        $res = $this->post('/folio/assets/' . $asset->id . '/delete');
        $this->assertSame('/folio/assets', $res->getHeaderLine('Location'));
        $this->assertNull($this->lib()->findBinned($asset->id));
    }

    public function test_with_bin_dir_delete_confirms_moving_to_the_bin(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);

        $this->assertStringContainsString('data-confirm="Move this file to the bin?"', (string) $this->get('/folio/assets/' . $asset->id)->getBody());
    }

    public function test_unused_filter_is_hidden_when_cleanup_is_off(): void
    {
        $this->assertStringNotContainsString('name="unused"', (string) $this->get('/folio/assets')->getBody());
    }

    public function test_unused_filter_lists_candidates_when_cleanup_is_on(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin', 'cleanup' => ['enabled' => true, 'min_age_days' => 7]]);
        $used = $this->lib()->upload($this->file($this->png(), 'used.png'), null);
        $unused = $this->lib()->upload($this->file($this->png(), 'unused.png'), null);
        $fresh = $this->lib()->upload($this->file($this->png(), 'fresh.png'), null);
        $this->age($used->id);
        $this->age($unused->id);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $used->id]);

        $all = (string) $this->get('/folio/assets')->getBody();
        $this->assertStringContainsString('name="unused" value="1"', $all);
        $this->assertStringContainsString($fresh->id, $all);

        $page = (string) $this->get('/folio/assets?unused=1')->getBody();
        $this->assertStringContainsString($unused->id, $page);
        $this->assertStringNotContainsString($used->id, $page);
        $this->assertStringNotContainsString($fresh->id, $page);
        $this->assertStringContainsString('name="unused" value="1" checked', $page);
        $this->assertStringContainsString('1 file', $page);

        $this->assertStringNotContainsString($unused->id, (string) $this->get('/folio/assets?unused=1&kind=document')->getBody());
        $this->assertStringNotContainsString($unused->id, (string) $this->get('/folio/assets?unused=1&q=nomatch')->getBody());
        $this->assertStringContainsString($unused->id, (string) $this->get('/folio/assets?unused=1&q=UNUSED')->getBody());
    }

    public function test_an_earlier_revision_is_a_usage_that_does_not_block_delete(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $publisher = $this->publisher();
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => '']), null);

        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();
        $this->assertStringContainsString('<li><a href="/folio/post/p1/edit">Hello</a> (earlier revision)</li>', $panel);
        $this->assertDoesNotMatchRegularExpression('/<button type="submit" class="btn btn-danger" disabled/', $panel);

        $this->assertSame('/folio/assets?done=binned', $this->post('/folio/assets/' . $asset->id . '/delete')->getHeaderLine('Location'));
        $this->assertNotNull($this->lib()->findBinned($asset->id));
    }

    public function test_without_bin_dir_an_earlier_revision_blocks_delete(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $publisher = $this->publisher();
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]), null);
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => '']), null);

        // A delete for good would break the revision: the button is off, the library refuses.
        $panel = (string) $this->get('/folio/assets/' . $asset->id)->getBody();
        $this->assertStringContainsString('(earlier revision)', $panel);
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn btn-danger" disabled/', $panel);

        $res = $this->post('/folio/assets/' . $asset->id . '/delete');
        $this->assertSame(409, $res->getStatusCode());
        $this->assertNotNull($this->lib()->find($asset->id));
    }

    public function test_a_file_that_cannot_go_into_the_bin_is_kept_with_an_error(): void
    {
        // bin_dir is a file: nothing can be moved into it.
        $this->write('storage/not-a-dir', 'x');
        $this->media(['bin_dir' => $this->dir . '/storage/not-a-dir']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $log = ini_set('error_log', $this->dir . '/error.log');
        try {
            $res = $this->post('/folio/assets/' . $asset->id . '/delete');
        } finally {
            ini_set('error_log', (string) $log);
        }

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Could not move photo.png into the bin. The error was logged.', (string) $res->getBody());
        $this->assertStringContainsString('could not move asset ' . $asset->id . ' into the bin', (string) file_get_contents($this->dir . '/error.log'));
        $this->assertNotNull($this->lib()->find($asset->id));
        $this->assertFileExists($this->lib()->file($asset));
    }

    public function test_delete_says_binned_once_when_it_came_from_a_page_that_said_so(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $first = $this->lib()->upload($this->file($this->png(), 'a.png'), null);
        $second = $this->lib()->upload($this->file($this->png(), 'b.png'), null);

        $once = $this->post('/folio/assets/' . $first->id . '/delete?back=' . rawurlencode('/folio/assets?view=list&done=binned'))->getHeaderLine('Location');
        $alone = $this->post('/folio/assets/' . $second->id . '/delete?back=' . rawurlencode('/folio/assets?done=binned'))->getHeaderLine('Location');

        $this->assertSame('/folio/assets?view=list&done=binned', $once);
        $this->assertSame('/folio/assets?done=binned', $alone);
    }

    public function test_the_poster_of_a_binned_file_links_to_the_bin(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $poster = $this->lib()->upload($this->file($this->png(), 'poster.png'), null);
        $doc = $this->lib()->upload($this->file("notes\n", 'clip.txt'), null);
        $this->lib()->update($doc->id, ['poster' => $poster->id]);
        $this->lib()->delete($doc->id);

        // The test app allows everything (no auth): assets.delete included.
        $this->assertStringContainsString('<a href="/folio/assets/bin">clip.txt</a> (poster of a file in the bin)', (string) $this->get('/folio/assets/' . $poster->id)->getBody());
    }

    public function test_a_record_pointing_at_a_binned_file_saves_and_shows_it_in_the_bin(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $asset = $this->lib()->upload($this->file($this->png(), 'photo.png'), null);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]);
        $publisher = $this->publisher();
        $publisher->publish($this->stored('post', 'p1'), null);
        $publisher->publish($this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => '']), null);
        $this->lib()->delete($asset->id);
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], 'cover' => $asset->id]); // a revision restored

        $form = (string) $this->get('/folio/post/p1/edit')->getBody();
        $this->assertStringContainsString('photo.png (in the bin)', $form);

        $res = $this->post('/folio/post/p1', ['title' => 'Hello again', 'slug' => 'hello', '_locale' => 'en', 'cover_ids' => ['', $asset->id]]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame($asset->id, $this->stored('post', 'p1')->get('cover'));

        $this->lib()->restoreFromBin($asset->id);
        $this->assertStringNotContainsString('(in the bin)', (string) $this->get('/folio/post/p1/edit')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_bin_needs_assets_delete(): void
    {
        $this->media(['bin_dir' => $this->dir . '/storage/media-bin']);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
                ['email' => 'deleter@site.test', 'password_hash' => $hash, 'roles' => ['deleter']],
            ]]],
            'session' => ['cookie' => 'folio_bin_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'viewer' => ['assets.view'],
            'deleter' => ['assets.view', 'assets.delete'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $viewer = $this->signedIn('viewer');
        $asset = $viewer[0]->container()->get(MediaLibrary::class)->upload($this->file($this->png(), 'photo.png'), null);
        $viewer[0]->container()->get(MediaLibrary::class)->delete($asset->id);
        $this->assertSame(403, $this->as($viewer, 'GET', '/folio/assets/bin')->getStatusCode());
        $this->assertSame(403, $this->as($viewer, 'POST', '/folio/assets/bin/' . $asset->id . '/restore')->getStatusCode());
        $this->assertStringNotContainsString('href="/folio/assets/bin"', (string) $this->as($viewer, 'GET', '/folio/assets')->getBody());

        // The poster of a binned file names the bin without a link for those who can't open it.
        $lib = $viewer[0]->container()->get(MediaLibrary::class);
        $poster = $lib->upload($this->file($this->png(), 'poster.png'), null);
        $clip = $lib->upload($this->file("notes\n", 'clip.txt'), null);
        $lib->update($clip->id, ['poster' => $poster->id]);
        $lib->delete($clip->id);
        $posterPanel = (string) $this->as($viewer, 'GET', '/folio/assets/' . $poster->id)->getBody();
        $this->assertStringContainsString('clip.txt (poster of a file in the bin)', $posterPanel);
        $this->assertStringNotContainsString('href="/folio/assets/bin"', $posterPanel);

        $deleter = $this->signedIn('deleter');
        $this->assertStringContainsString('<a href="/folio/assets/bin">clip.txt</a>', (string) $this->as($deleter, 'GET', '/folio/assets/' . $poster->id)->getBody());
        $this->assertSame(200, $this->as($deleter, 'GET', '/folio/assets/bin')->getStatusCode());
        $this->assertStringContainsString('href="/folio/assets/bin"', (string) $this->as($deleter, 'GET', '/folio/assets')->getBody());
        $this->assertSame(302, $this->as($deleter, 'POST', '/folio/assets/bin/' . $asset->id . '/restore')->getStatusCode());
    }

    /** @return array{Application, string} an app with the user signed in, and its CSRF token */
    private function signedIn(string $role): array
    {
        $app = Application::create($this->dir);
        $app->boot();
        $f = new Psr17Factory();
        $page = (string) $app->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $token = $m[1] ?? '';
        $login = $app->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());

        return [$app, $token];
    }

    /** @param array{Application, string} $session */
    private function as(array $session, string $method, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $session[1]]);
        }

        return $session[0]->handle($req);
    }

    /** @param array<string, mixed> $config */
    private function media(array $config): void
    {
        $this->write('config/media.php', "<?php\nreturn " . var_export($config, true) . ";\n");
    }

    /** Uploaded long ago: old enough for cleanup. */
    private function age(string $id): void
    {
        $dm = $this->dm();
        $record = $dm->findIn($this->lib()->definition(), $id) ?? throw new \RuntimeException("missing asset {$id}");
        $record->set('created_at', '2020-01-01T00:00:00+00:00');
        $dm->saveType($record, false);
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
