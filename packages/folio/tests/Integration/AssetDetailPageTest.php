<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Media\Asset;
use Preflow\Media\CropRect;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\StoredAssets;
use Psr\Http\Message\ResponseInterface;

/**
 * An asset's details are their own page ({prefix}/assets/{id}), reached from the library with the
 * library's query as ?back=; it has the Details / Edit image tabs and keeps "back" through every
 * redirect. Only a relative {prefix}/assets back is accepted.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class AssetDetailPageTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private string $token = '';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
            ]]],
            'session' => ['cookie' => 'folio_editor_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'editor' => ['folio.dashboard', 'assets.view', 'assets.edit', 'assets.delete'],
            'viewer' => ['folio.dashboard', 'assets.view'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $this->write('config/image.php', "<?php\nreturn " . var_export(['presets' => [
            'cover-thumb' => ['width' => 100, 'height' => 100, 'fit' => 'cover'],
            'card' => ['width' => 120, 'height' => 80, 'fit' => 'contain'],
            'hero' => ['widths' => [480, 960], 'sizes' => '100vw'],
        ]], true) . ";\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_the_grid_links_to_the_page_with_the_library_query_and_has_no_panel(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $body = (string) $this->send('GET', '/folio/assets?kind=image&view=grid')->getBody();

        $this->assertStringNotContainsString('hx-get', $body);
        $this->assertStringNotContainsString('folio-asset-panel', $body);
        $back = rawurlencode('/folio/assets?kind=image&view=grid&page=1');
        $this->assertMatchesRegularExpression('#href="/folio/assets/' . $asset->id . '\?back=[^"]+"#', $body);
        $this->assertStringContainsString('/folio/assets/' . $asset->id . '?back=' . $back, html_entity_decode($body));
    }

    #[RunInSeparateProcess]
    public function test_the_page_is_always_full_with_tabs_two_columns_and_the_back_link(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id)
            ->withQueryParams(['back' => '/folio/assets?kind=image'])
            ->withHeader('HX-Request', 'true');

        $res = $this->app()->handle($req);

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('<html', $body);
        $this->assertStringContainsString('class="folio-asset-tabs"', $body);
        $this->assertStringContainsString('>Details</a>', $body);
        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets/' . $asset->id . '/edit\?back=[^"]*"[^>]*>Edit image</a>#', $body);
        $this->assertStringContainsString('folio-asset-columns', $body);
        $this->assertStringContainsString('data-folio-focal', $body);
        $this->assertStringContainsString('folio-swatches', $body);
        $this->assertStringContainsString('name="alt[en]"', $body);
        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets\?kind=image"[^>]*>← Assets</a>#', $body);
        $this->assertLessThan(strpos($body, 'folio-asset-usage'), strpos($body, 'folio-asset-columns'));
    }

    #[RunInSeparateProcess]
    public function test_the_back_link_defaults_without_a_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $body = (string) $this->send('GET', '/folio/assets/' . $asset->id)->getBody();

        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets"[^>]*>← Assets</a>#', $body);
        $this->assertStringContainsString('href="/folio/assets/' . $asset->id . '/edit"', $body);
    }

    /** @return iterable<string, array{string}> */
    public static function badBacks(): iterable
    {
        yield 'absolute' => ['https://evil.example/'];
        yield 'protocol relative' => ['//evil.example'];
        yield 'another path' => ['/folio/post'];
        yield 'prefix lookalike' => ['/folio/assets-evil'];
        yield 'backslash' => ['/folio/assets\\evil'];
        yield 'double slash' => ['/folio/assets//evil'];
        yield 'newline' => ["/folio/assets\nSet-Cookie: a=b"];
        yield 'scheme' => ['javascript:alert(1)'];
    }

    #[DataProvider('badBacks')]
    #[RunInSeparateProcess]
    public function test_an_unsafe_back_falls_back_to_the_library(string $back): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id)->withQueryParams(['back' => $back]);

        $body = (string) $this->app()->handle($req)->getBody();

        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets"[^>]*>← Assets</a>#', $body);
        $this->assertStringNotContainsString('evil', $body);
        $this->assertStringNotContainsString('javascript', $body);

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $asset->id)
            ->withQueryParams(['back' => $back])
            ->withParsedBody(['alt' => ['en' => 'x'], '_csrf_token' => $this->token]));
        $this->assertSame('/folio/assets/' . $asset->id . '?saved=1', $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_saving_returns_to_the_page_with_the_same_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $back = '/folio/assets?kind=image&page=2';

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $asset->id . '?back=' . rawurlencode($back))
            ->withQueryParams(['back' => $back])
            ->withParsedBody(['alt' => ['en' => 'Quadrants'], '_csrf_token' => $this->token]));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '?saved=1&back=' . rawurlencode($back), $res->getHeaderLine('Location'));
        $this->assertSame('Quadrants', $this->lib()->find($asset->id)?->alt['en'] ?? null);

        $page = (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('Saved.', $page);
    }

    #[RunInSeparateProcess]
    public function test_the_forms_of_the_page_carry_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id)->withQueryParams(['back' => '/folio/assets?q=a']);

        $body = (string) $this->app()->handle($req)->getBody();

        $enc = htmlspecialchars('?back=' . rawurlencode('/folio/assets?q=a'), ENT_QUOTES);
        foreach (['', '/replace', '/delete'] as $suffix) {
            $this->assertStringContainsString('action="/folio/assets/' . $asset->id . $suffix . $enc . '"', $body);
        }
    }

    #[RunInSeparateProcess]
    public function test_deleting_an_unused_asset_returns_to_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $back = '/folio/assets?kind=image';

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $asset->id . '/delete')
            ->withQueryParams(['back' => $back])
            ->withParsedBody(['_csrf_token' => $this->token]));

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame($back, $res->getHeaderLine('Location'));
        $this->assertNull($this->lib()->find($asset->id));

        $other = $this->quadrant();
        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $other->id . '/delete')
            ->withQueryParams(['back' => 'https://evil.example/'])
            ->withParsedBody(['_csrf_token' => $this->token]));
        $this->assertSame('/folio/assets', $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_replace_restore_and_delete_version_keep_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $back = '/folio/assets?kind=image';
        $suffix = '&back=' . rawurlencode($back);
        $f = new Psr17Factory();
        $post = fn (string $uri, array $files = []) => $this->app()->handle($f->createServerRequest('POST', $uri)
            ->withQueryParams(['back' => $back])
            ->withParsedBody(['_csrf_token' => $this->token])
            ->withUploadedFiles($files));

        $png = $this->dir . '/new.png';
        $im = imagecreatetruecolor(20, 20);
        imagepng($im, $png);
        $upload = $f->createUploadedFile($f->createStreamFromFile($png), (int) filesize($png), UPLOAD_ERR_OK, 'new.png', 'image/png');
        $res = $post('/folio/assets/' . $asset->id . '/replace', ['file' => $upload]);
        $this->assertSame('/folio/assets/' . $asset->id . '?done=replaced' . $suffix, $res->getHeaderLine('Location'));

        $res = $post('/folio/assets/' . $asset->id . '/versions/1/restore');
        $this->assertSame('/folio/assets/' . $asset->id . '?done=restored&version=1' . $suffix, $res->getHeaderLine('Location'));

        $res = $post('/folio/assets/' . $asset->id . '/versions/2/delete');
        $this->assertSame('/folio/assets/' . $asset->id . '?done=version-deleted' . $suffix, $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_the_edit_screen_has_the_same_tabs_and_edits_keep_back(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $back = '/folio/assets?kind=image';
        $req = (new Psr17Factory())->createServerRequest('GET', '/folio/assets/' . $asset->id . '/edit')->withQueryParams(['back' => $back]);

        $body = (string) $this->app()->handle($req)->getBody();

        $this->assertStringContainsString('class="folio-asset-tabs"', $body);
        $q = htmlspecialchars('?back=' . rawurlencode($back), ENT_QUOTES);
        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets/' . $asset->id . preg_quote($q, '#') . '"[^>]*>Details</a>#', $body);
        $this->assertMatchesRegularExpression('#<a [^>]*aria-current="page"[^>]*>Edit image</a>#', $body);
        $this->assertStringNotContainsString('Back to the asset', $body);
        $this->assertStringContainsString('action="/folio/assets/' . $asset->id . '/edit' . $q . '"', $body);
        $this->assertMatchesRegularExpression('#<a [^>]*href="/folio/assets\?kind=image"[^>]*>← Assets</a>#', $body);

        $res = $this->app()->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $asset->id . '/edit')
            ->withQueryParams(['back' => $back])
            ->withParsedBody(['op' => 'rotate-right', '_csrf_token' => $this->token]));
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=rotated&back=' . rawurlencode($back), $res->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_a_pdf_page_has_no_edit_tab_and_a_viewer_neither(): void
    {
        $this->as('editor');
        $pdf = $this->pdf();
        $image = $this->quadrant();

        $body = (string) $this->send('GET', '/folio/assets/' . $pdf->id)->getBody();
        $this->assertStringContainsString('class="folio-asset-tabs"', $body);
        $this->assertStringNotContainsString('Edit image', $body);

        $this->session = null;
        $this->as('viewer');
        $body = (string) $this->send('GET', '/folio/assets/' . $image->id)->getBody();
        $this->assertStringContainsString('>Details</a>', $body);
        $this->assertStringNotContainsString('Edit image', $body);
    }

    #[RunInSeparateProcess]
    public function test_the_picker_drawer_still_selects_and_opens_no_details(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        foreach (['grid', 'list'] as $view) {
            $body = (string) $this->send('GET', '/folio/assets?picker=1&multiple=0&view=' . $view)->getBody();

            $this->assertStringContainsString('data-folio-pick', $body);
            $this->assertStringContainsString('data-id="' . $asset->id . '"', $body);
            $this->assertStringNotContainsString('href="/folio/assets/' . $asset->id, $body);
            $this->assertStringNotContainsString('hx-get', $body);
        }
    }

    private function lib(): MediaLibrary
    {
        return $this->app()->container()->get(MediaLibrary::class);
    }

    /** One app per test: the login session lives in-process. */
    private function app(): Application
    {
        if ($this->session === null) {
            $this->session = Application::create($this->dir);
            $this->session->boot();
        }

        return $this->session;
    }

    private function as(string $role): void
    {
        $f = new Psr17Factory();
        $page = (string) $this->app()->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->app()->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, string> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody($body + ['_csrf_token' => $this->token]);
        }

        return $this->app()->handle($req);
    }

    /** @param array<string, string> $body */
    private function edit(Asset $asset, array $body): ResponseInterface
    {
        return $this->send('POST', '/folio/assets/' . $asset->id . '/edit', $body);
    }

    /** @param array<string, mixed> $changes */
    private function patch(Asset $asset, array $changes): Asset
    {
        return StoredAssets::patch($this->app()->container()->get(\Preflow\Data\DataManager::class), $this->lib(), $asset, $changes);
    }

    /** A 200x100 PNG in four colours: red, green (top), blue, yellow (bottom). */
    private function quadrant(): Asset
    {
        $image = imagecreatetruecolor(200, 100);
        foreach ([[0, 0, 255, 0, 0], [100, 0, 0, 255, 0], [0, 50, 0, 0, 255], [100, 50, 255, 255, 0]] as [$x, $y, $r, $g, $b]) {
            imagefilledrectangle($image, $x, $y, $x + 99, $y + 49, (int) imagecolorallocate($image, $r, $g, $b));
        }
        $file = $this->dir . '/quadrant-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($image, $file);

        return $this->lib()->importFile($file, 'quadrant.png', null);
    }

    private function pdf(): Asset
    {
        $file = $this->dir . '/rules-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($file, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");

        return $this->lib()->importFile($file, 'rules.pdf', null);
    }
}
