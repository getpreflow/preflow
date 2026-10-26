<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
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
 * Folio's Edit image screen ({prefix}/assets/{id}/edit), through the real app with a signed-in
 * staff user: rotate, mirror, the image crop and the cover presets' cuts, each a call of the
 * media core. A refused edit shows the core's message; the screen needs assets.edit.
 */
#[RequiresPhpExtension('gd')]
#[RequiresFunction('imagewebp')]
final class AssetEditorTest extends TestCase
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
            'editor' => ['folio.dashboard', 'assets.view', 'assets.edit'],
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
    public function test_the_screen_shows_the_oriented_image_and_each_box_preset_with_its_cut(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->send('GET', '/folio/assets/' . $asset->id . '/edit');

        $this->assertSame(200, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('src="' . $this->lib()->orientedUrl($asset) . '"', $body);
        $this->assertStringContainsString('cover-thumb', $body);
        $this->assertStringContainsString('100×100, cover', $body);
        $plan = $this->lib()->boxPlan($asset, 'cover-thumb');
        $this->assertNotNull($plan);
        $this->assertStringContainsString('src="' . $this->lib()->url($plan->base . '-' . $plan->width . '.webp') . '"', $body);
        $this->assertStringContainsString('120×80, contain', $body);
        $this->assertStringNotContainsString('data-preset="hero"', $body); // a width-only preset is no box
        $this->assertSame(1, preg_match_all('/data-editor-adjust/', $body), 'Adjust only for the cover preset');
        $this->assertMatchesRegularExpression('#<script src="[^"]*cropper\.js[^"]*" defer>#', $body);
        $this->assertMatchesRegularExpression('#<script src="[^"]*image-editor\.js[^"]*" defer>#', $body);
        $this->assertMatchesRegularExpression('#<link rel="stylesheet" href="[^"]*cropper\.css[^"]*">#', $body);
        foreach (['rotate-left', 'rotate-right', 'flip-h', 'flip-v', 'crop', 'format-crop'] as $op) {
            $this->assertStringContainsString('name="op" value="' . $op . '"', $body);
        }
        $this->assertStringContainsString('<option value="1.7778">16:9</option>', $body);
        // The page itself, without the debug toolbar (APP_DEBUG=1 in the scaffold) and its own styles.
        $page = explode('<div id="preflow-debug-toolbar"', $body)[0];
        $this->assertStringNotContainsString('style="', $page);
        $this->assertStringNotContainsString('<style', $page);
        $this->assertStringContainsString('Nothing the production CSP would block', $body); // the toolbar's own check
    }

    #[RunInSeparateProcess]
    public function test_rotate_and_mirror(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->edit($asset, ['op' => 'rotate-right']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=rotated', $res->getHeaderLine('Location'));
        $this->assertSame(1, $this->lib()->find($asset->id)?->rotation);
        $this->assertStringContainsString('Rotated.', (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody());

        $this->edit($asset, ['op' => 'rotate-left']);
        $this->assertSame(0, $this->lib()->find($asset->id)?->rotation);

        $res = $this->edit($asset, ['op' => 'flip-h']);
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=mirrored', $res->getHeaderLine('Location'));
        $this->assertTrue($this->lib()->find($asset->id)?->mirrored);
        $this->edit($asset, ['op' => 'flip-v']);
        $flipped = $this->lib()->find($asset->id);
        $this->assertSame([false, 2], [$flipped?->mirrored, $flipped?->rotation]); // left-right then top-bottom: a half turn
    }

    #[RunInSeparateProcess]
    public function test_crop_and_remove_the_crop(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->edit($asset, ['op' => 'crop', 'x' => '50', 'y' => '50', 'w' => '50', 'h' => '50']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=cropped', $res->getHeaderLine('Location'));
        $cropped = $this->lib()->find($asset->id);
        $this->assertEquals(CropRect::of(50, 50, 50, 50), $cropped?->crop);
        $this->assertSame([100, 50], [$this->lib()->deliveryAsset($cropped)?->width, $this->lib()->deliveryAsset($cropped)?->height]);

        $res = $this->edit($asset, ['op' => 'crop', 'clear' => '1']);
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=crop-removed', $res->getHeaderLine('Location'));
        $this->assertNull($this->lib()->find($asset->id)?->crop);
    }

    #[RunInSeparateProcess]
    public function test_a_crop_without_an_area_is_refused(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->edit($asset, ['op' => 'crop', 'x' => '', 'y' => '', 'w' => '', 'h' => '']);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('role="alert"', (string) $res->getBody());
        $this->assertNull($this->lib()->find($asset->id)?->crop);
    }

    #[RunInSeparateProcess]
    public function test_format_crop_and_reset(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->edit($asset, ['op' => 'format-crop', 'preset' => 'cover-thumb', 'x' => '0', 'y' => '0', 'w' => '50', 'h' => '50']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=format-cropped&preset=cover-thumb', $res->getHeaderLine('Location'));
        $this->assertEquals(['cover-thumb' => CropRect::of(0, 0, 50, 50)], $this->lib()->find($asset->id)?->crops);
        $page = (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('Saved the cut of cover-thumb.', $page);
        $this->assertMatchesRegularExpression('#name="preset" value="cover-thumb">\s*<input type="hidden" name="reset" value="1">#', $page);

        $res = $this->edit($asset, ['op' => 'format-crop', 'preset' => 'cover-thumb', 'reset' => '1']);
        $this->assertSame('/folio/assets/' . $asset->id . '/edit?done=format-reset&preset=cover-thumb', $res->getHeaderLine('Location'));
        $this->assertSame([], $this->lib()->find($asset->id)?->crops);
        $this->assertStringNotContainsString('name="reset"', (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_refused_edit_shows_the_core_message_and_changes_nothing(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $before = $this->lib()->find($asset->id)?->toData();

        $res = $this->edit($asset, ['op' => 'format-crop', 'preset' => 'nope', 'x' => '0', 'y' => '0', 'w' => '50', 'h' => '50']);

        $this->assertSame(422, $res->getStatusCode());
        $body = (string) $res->getBody();
        $this->assertStringContainsString('role="alert"', $body);
        $this->assertStringContainsString('Preset &quot;nope&quot; is not a cover box in config/image.php', $body);
        $this->assertStringContainsString('data-folio-image-editor', $body); // the screen again
        $this->assertSame($before, $this->lib()->find($asset->id)?->toData());

        $this->assertSame(422, $this->edit($asset, ['op' => 'format-crop', 'preset' => 'card', 'x' => '0', 'y' => '0', 'w' => '50', 'h' => '50'])->getStatusCode());
        $this->assertSame(422, $this->edit($asset, ['op' => 'explode'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_an_image_that_cannot_be_edited_has_no_screen(): void
    {
        $this->as('editor');
        $pdf = $this->pdf();
        $private = $this->patch($this->quadrant(), ['private' => true]);

        $this->assertSame(404, $this->send('GET', '/folio/assets/' . $pdf->id . '/edit')->getStatusCode());
        $this->assertSame(404, $this->send('GET', '/folio/assets/' . $private->id . '/edit')->getStatusCode());
        $this->assertSame(404, $this->send('GET', '/folio/assets/' . str_repeat('a', 32) . '/edit')->getStatusCode());
        $res = $this->edit($pdf, ['op' => 'rotate-right']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('cannot be edited', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_without_assets_edit_both_routes_are_403(): void
    {
        $this->as('viewer');
        $asset = $this->quadrant();

        $this->assertSame(403, $this->send('GET', '/folio/assets/' . $asset->id . '/edit')->getStatusCode());
        $this->assertSame(403, $this->edit($asset, ['op' => 'rotate-right'])->getStatusCode());
        $this->assertSame(0, $this->lib()->find($asset->id)?->rotation);
        $this->assertStringNotContainsString('/edit"', (string) $this->send('GET', '/folio/assets/' . $asset->id)->getBody());
    }

    #[RunInSeparateProcess]
    public function test_a_post_without_the_csrf_token_is_refused(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();

        $res = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/assets/' . $asset->id . '/edit')
            ->withParsedBody(['op' => 'rotate-right']));

        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame(0, $this->lib()->find($asset->id)?->rotation);
    }

    #[RunInSeparateProcess]
    public function test_the_panel_offers_edit_image_and_after_an_edit_the_original(): void
    {
        $this->as('editor');
        $asset = $this->quadrant();
        $pdf = $this->pdf();
        $private = $this->patch($this->quadrant(), ['private' => true]);

        $panel = (string) $this->send('GET', '/folio/assets/' . $asset->id)->getBody();
        $this->assertStringContainsString('href="/folio/assets/' . $asset->id . '/edit"', $panel);
        $this->assertStringContainsString('Edit image', $panel);
        $this->assertStringNotContainsString('Download original', $panel);
        $this->assertStringContainsString(' · 200×100</p>', $panel);
        $this->assertStringNotContainsString('Edit image', (string) $this->send('GET', '/folio/assets/' . $pdf->id)->getBody());
        $this->assertStringNotContainsString('Edit image', (string) $this->send('GET', '/folio/assets/' . $private->id)->getBody());

        $this->edit($asset, ['op' => 'crop', 'x' => '50', 'y' => '0', 'w' => '50', 'h' => '100']);
        $panel = (string) $this->send('GET', '/folio/assets/' . $asset->id)->getBody();
        $this->assertMatchesRegularExpression('#<a [^>]*href="/media/' . preg_quote($asset->path, '#') . '"[^>]*download[^>]*>Download original</a>#', $panel);
        $this->assertStringContainsString(' · 100×100 (original 200×100)</p>', $panel);
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
