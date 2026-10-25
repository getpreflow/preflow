<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Assets;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Http\Session\SessionInterface;
use Preflow\Media\Asset;
use Preflow\Folio\Assets\AssetLibrary;
use Preflow\Folio\Assets\ImageTag;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HandlesUpload;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Private assets: an asset field with "private": true stores its uploads under
 * assets.private_dir (outside public/), and only {private_url}/{id} serves them,
 * to signed-in users with assets.private_permission ("members.access" here).
 * Staff: admin ("*") and editor (no members.access); members: member and guest.
 */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
final class PrivateAssetsTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;

    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => [
                'session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'staff', 'population' => 'staff'],
                'members' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'members', 'population' => 'members'],
            ],
            'providers' => [
                'staff' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                    ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ]],
                'members' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                    ['email' => 'member@site.test', 'password_hash' => $hash, 'roles' => ['member']],
                    ['email' => 'guest@site.test', 'password_hash' => $hash, 'roles' => []],
                ]],
            ],
            'session' => ['cookie' => 'folio_private_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export([
            'staff' => ['admin' => ['*'], 'editor' => ['folio.dashboard', 'assets.*']],
            'members' => ['member' => ['members.access']],
        ], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");
        $this->write('app/Folio/Members/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Members;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'members', label: 'Members')]
            #[\Preflow\Folio\Extension\Attributes\DeclaresPermission('members.access', 'Members area')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('config/folio/extensions.json', '{"enabled": {"members": {"disabled_parts": []}}}');
        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
        $this->folioConfig([]);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    /** config/folio.php with these "assets" settings on top of the private route of the smart-stack setup. */
    private function folioConfig(array $assets): void
    {
        $this->write('config/folio.php', "<?php\nreturn " . var_export([
            'path' => '/folio',
            'assets' => $assets + ['private_url' => '/tools/files', 'private_permission' => 'members.access'],
        ], true) . ";\n");
    }

    /** One app per test: the session lives in-process. */
    private function app(): Application
    {
        if ($this->session === null) {
            $this->session = Application::create($this->dir);
            $this->session->boot();
        }

        return $this->session;
    }

    private function lib(): AssetLibrary
    {
        return $this->app()->container()->get(AssetLibrary::class);
    }

    private function signIn(string $key, string $email): void
    {
        $this->app()->container()->get(SessionInterface::class)->set($key, $email);
    }

    private function fetch(string $uri): ResponseInterface
    {
        return $this->app()->handle((new Psr17Factory())->createServerRequest('GET', $uri));
    }

    private function file(string $bytes, string $name): UploadedFileInterface
    {
        $f = new Psr17Factory();

        return $f->createUploadedFile($f->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, $name, 'application/octet-stream');
    }

    private function png(int $width = 1200, int $height = 800): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** Uploads through an asset field with "private": true, as the admin form does. */
    private function uploadPrivate(UploadedFileInterface $file): Asset
    {
        $field = $this->app()->container()->get(FieldTypeRegistry::class)->get('asset');
        $this->assertInstanceOf(HandlesUpload::class, $field);
        $id = $field->storeUploads([$file], [], ['asset' => ['private' => true]]);
        $this->assertIsString($id);

        return $this->lib()->find($id) ?? throw new \RuntimeException('not stored: ' . implode(' ', $field->drainErrors()));
    }

    /**
     * Every file under a directory, relative.
     *
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $out[] = substr($f->getPathname(), strlen($dir) + 1);
        }

        return $out;
    }

    #[RunInSeparateProcess]
    public function test_private_upload_stored_outside_public(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $img = $this->uploadPrivate($this->file($this->png(), 'Chart.png'));

        $this->assertTrue($pdf->private);
        $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/' . $pdf->id . '\.pdf$#', $pdf->path);
        $this->assertFileExists($this->dir . '/storage/private-media/' . $pdf->path);
        $this->assertFileExists($this->dir . '/storage/private-media/' . $img->path);
        $this->assertSame([], $img->variants); // no variants for private images
        $this->assertSame([$img->path], array_values(array_filter(
            $this->filesUnder($this->dir . '/storage/private-media'),
            static fn (string $f) => str_contains($f, $img->id),
        )));
        $this->assertSame([], $this->filesUnder($this->dir . '/public'));
        $this->assertTrue($this->lib()->find($pdf->id)?->private);

        // Deleting removes the private file.
        $this->lib()->delete($pdf->id);
        $this->assertFileDoesNotExist($this->dir . '/storage/private-media/' . $pdf->path);
    }

    #[RunInSeparateProcess]
    public function test_private_asset_not_under_public_media(): void
    {
        $img = $this->uploadPrivate($this->file($this->png(), 'Chart.png'));
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $base = substr($img->path, 0, (int) strrpos($img->path, '.'));

        // Nothing under the public media URL serves or makes it.
        $this->assertSame(404, $this->fetch('/media/' . $img->path)->getStatusCode());
        $this->assertSame(404, $this->fetch('/media/' . $base . '-480.webp')->getStatusCode());
        $this->assertSame([], $this->filesUnder($this->dir . '/public'));

        // The only URL is the gated route: library, folio_asset_url, folio_image, admin thumbnails.
        $this->assertSame('/tools/files/' . $img->id, $this->lib()->url($img));
        $this->assertSame('/tools/files/' . $img->id, $this->lib()->thumbnailUrl($img));
        $tag = $this->app()->container()->get(ImageTag::class);
        $this->assertSame('/tools/files/' . $pdf->id, $tag->url($pdf->id));
        $html = $tag->render($img->id, null, ['priority' => true]);
        $this->assertStringContainsString('src="/tools/files/' . $img->id . '"', $html);
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertStringNotContainsString('/media/', $html);
        $this->assertStringContainsString('href="/tools/files/' . $pdf->id . '"', $tag->render($pdf->id));
    }

    #[RunInSeparateProcess]
    public function test_private_url_requires_permission(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));

        $out = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(403, $out->getStatusCode());
        $this->assertSame('private, no-store', $out->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('%PDF', (string) $out->getBody());

        // Signed in without the permission: a member without it, and staff without it.
        $this->signIn('_auth_user_id.members', 'guest@site.test');
        $this->assertSame(403, $this->fetch('/tools/files/' . $pdf->id)->getStatusCode());
        $this->signIn('_auth_user_id', 'editor@site.test');
        $this->assertSame(403, $this->fetch('/tools/files/' . $pdf->id)->getStatusCode());

        // Staff with "*" pass.
        $this->signIn('_auth_user_id', 'admin@site.test');
        $this->assertSame(200, $this->fetch('/tools/files/' . $pdf->id)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_private_url_redirects_to_configured_login(): void
    {
        $this->folioConfig(['private_login' => '/tools/login']);
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));

        $res = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/tools/login?next=' . rawurlencode('/tools/files/' . $pdf->id), $res->getHeaderLine('Location'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_member_downloads_pdf_as_attachment_no_store(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Members Guide.pdf'));
        $img = $this->uploadPrivate($this->file($this->png(40, 30), 'Chart.png'));
        $this->signIn('_auth_user_id.members', 'member@site.test');

        $res = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame($this->pdf(), (string) $res->getBody());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $res->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('attachment; filename="Members Guide.pdf"', $res->getHeaderLine('Content-Disposition'));
        $this->assertSame('nosniff', $res->getHeaderLine('X-Content-Type-Options'));

        $image = $this->fetch('/tools/files/' . $img->id);
        $this->assertSame(200, $image->getStatusCode());
        $this->assertSame('image/png', $image->getHeaderLine('Content-Type'));
        $this->assertStringStartsWith('inline', $image->getHeaderLine('Content-Disposition'));
        $this->assertSame('private, no-store', $image->getHeaderLine('Cache-Control'));
    }

    #[RunInSeparateProcess]
    public function test_traversal_rejected(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $public = $this->lib()->upload($this->file($this->png(40, 30), 'Open.png'), null);
        $this->signIn('_auth_user_id.members', 'member@site.test');

        // A record whose path points outside the private dir.
        $data = $pdf->toData();
        $data['path'] = '../../config/app.php';
        $this->dm()->saveType(\Preflow\Data\DynamicRecord::fromArray($this->lib()->definition(), $data), false);

        $escaped = $this->fetch('/tools/files/' . $pdf->id);
        $this->assertSame(404, $escaped->getStatusCode());
        $this->assertStringNotContainsString('folio-integration-test-key', (string) $escaped->getBody());
        $this->assertSame('private, no-store', $escaped->getHeaderLine('Cache-Control'));

        $this->assertNotSame(200, $this->fetch('/tools/files/..%2F..%2Fconfig%2Fapp.php')->getStatusCode());
        $this->assertNotSame(200, $this->fetch('/tools/files/../../config/app.php')->getStatusCode());
        $this->assertSame(404, $this->fetch('/tools/files/' . str_repeat('0', 32))->getStatusCode());
        // A public asset is not served by the private route.
        $this->assertSame(404, $this->fetch('/tools/files/' . $public->id)->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_private_dir_inside_public_is_refused(): void
    {
        $this->folioConfig(['private_dir' => $this->dir . '/public/../public/secret']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be outside public/');
        $this->app();
    }

    /** The asset field type and a resolve() of these posted ids for a private or public field. */
    private function resolveIds(array $ids, bool $private): array
    {
        $field = $this->app()->container()->get(FieldTypeRegistry::class)->get('asset');
        $this->assertInstanceOf(\Preflow\Folio\Field\Types\AssetFieldType::class, $field);
        $value = $field->resolve(['', ...$ids], null, [], ['asset' => ['multiple' => true, 'private' => $private]], true, null);

        return [json_decode($value, true), $field->drainErrors()];
    }

    #[RunInSeparateProcess]
    public function test_public_asset_refused_in_private_field(): void
    {
        $public = $this->lib()->upload($this->file($this->png(40, 30), 'Open.png'), null);
        $private = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));

        [$kept, $errors] = $this->resolveIds([$public->id, $private->id], true);
        $this->assertSame([$private->id], $kept);
        $this->assertSame(['Open.png is a public file; upload it here to make it private.'], $errors);
    }

    #[RunInSeparateProcess]
    public function test_private_asset_refused_in_public_field(): void
    {
        $public = $this->lib()->upload($this->file($this->png(40, 30), 'Open.png'), null);
        $private = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));

        [$kept, $errors] = $this->resolveIds([$public->id, $private->id], false);
        $this->assertSame([$public->id], $kept);
        $this->assertSame(['Guide.pdf is a private file; it can only be used in a private field.'], $errors);
    }

    #[RunInSeparateProcess]
    public function test_picker_lists_and_uploads_matching_assets(): void
    {
        $public = $this->lib()->upload($this->file($this->png(40, 30), 'Open.png'), null);
        $private = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $this->signIn('_auth_user_id', 'admin@site.test');

        // The field's picker URL names its privacy.
        $field = $this->app()->container()->get(FieldTypeRegistry::class)->get('asset');
        $ctx = new \Preflow\Folio\Field\FieldContext(name: 'pdf', value: null, config: ['asset' => ['private' => true]]);
        $this->assertStringContainsString('data-picker-url="/folio/assets?picker=1&amp;multiple=0&amp;private=1"', $field->renderEditor($ctx));

        $onlyPrivate = (string) $this->fetch('/folio/assets?picker=1&multiple=0&private=1')->getBody();
        $this->assertStringContainsString('data-id="' . $private->id . '"', $onlyPrivate);
        $this->assertStringNotContainsString('data-id="' . $public->id . '"', $onlyPrivate);
        $this->assertStringContainsString('name="private" value="1"', $onlyPrivate); // kept by search and upload
        $onlyPublic = (string) $this->fetch('/folio/assets?picker=1&multiple=0&private=0')->getBody();
        $this->assertStringContainsString('data-id="' . $public->id . '"', $onlyPublic);
        $this->assertStringNotContainsString('data-id="' . $private->id . '"', $onlyPublic);

        // An upload from the private picker is private.
        preg_match('/name="_csrf_token" value="([^"]+)"/', $onlyPrivate, $m);
        $f = new Psr17Factory();
        $res = $this->app()->handle($f->createServerRequest('POST', '/folio/assets')
            ->withParsedBody(['_csrf_token' => $m[1] ?? '', 'picker' => '1', 'multiple' => '0', 'private' => '1'])
            ->withUploadedFiles(['files' => [$this->file($this->pdf(), 'New.pdf')]]));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringContainsString('private=1', $res->getHeaderLine('Location'));
        $new = array_values(array_filter($this->lib()->all(), static fn (Asset $a) => $a->originalName === 'New.pdf'));
        $this->assertCount(1, $new);
        $this->assertTrue($new[0]->private);
    }

    #[RunInSeparateProcess]
    public function test_private_field_without_library_refuses_upload(): void
    {
        $uploads = $this->dir . '/storage/uploads';
        $field = new \Preflow\Folio\Field\Types\AssetFieldType($uploads, '/folio/_uploads');

        $value = $field->storeUploads([$this->file($this->pdf(), 'Guide.pdf')], [], ['asset' => ['private' => true]]);
        $this->assertSame('', $value);
        $this->assertSame(['Guide.pdf: private files need the asset library; it was not stored.'], $field->drainErrors());
        $this->assertSame([], $this->filesUnder($uploads));
    }

    #[RunInSeparateProcess]
    public function test_private_download_is_streamed_from_disk(): void
    {
        $pdf = $this->uploadPrivate($this->file($this->pdf(), 'Guide.pdf'));
        $this->signIn('_auth_user_id.members', 'member@site.test');

        $body = $this->fetch('/tools/files/' . $pdf->id)->getBody();
        $this->assertSame(realpath($this->dir . '/storage/private-media/' . $pdf->path), $body->getMetadata('uri'));
        $this->assertSame($this->pdf(), (string) $body);
    }

    #[RunInSeparateProcess]
    public function test_public_assets_unchanged(): void
    {
        $asset = $this->lib()->upload($this->file($this->png(), 'Sea.png'), null);

        $this->assertFalse($asset->private);
        $this->assertFileExists($this->dir . '/public/media/' . $asset->path);
        $this->assertSame([], $this->filesUnder($this->dir . '/storage/private-media'));
        $this->assertNotSame([], $asset->variants);
        $this->assertSame('/media/' . $asset->path, $this->lib()->url($asset));
        $this->assertSame('/media/' . $asset->path, $this->lib()->url($asset->path));
        $html = $this->app()->container()->get(ImageTag::class)->render($asset->id);
        $this->assertStringContainsString('srcset="/media/', $html);
        $this->assertSame('/media/' . $asset->path, $this->app()->container()->get(ImageTag::class)->url($asset->id));

        // A field without "private" stores publicly, like before.
        $field = $this->app()->container()->get(FieldTypeRegistry::class)->get('asset');
        $this->assertInstanceOf(HandlesUpload::class, $field);
        $open = $this->lib()->find((string) $field->storeUploads([$this->file($this->pdf(), 'Open.pdf')], [], ['asset' => []]));
        $this->assertFalse($open?->private);
        $this->assertFileExists($this->dir . '/public/media/' . $open->path);

        // A record stored before the flag existed reads as public.
        $data = $asset->toData();
        unset($data['private']);
        $this->dm()->saveType(\Preflow\Data\DynamicRecord::fromArray($this->lib()->definition(), $data), false);
        $this->assertFalse($this->lib()->find($asset->id)?->private);

        // Without a private route match, a page under the private URL's prefix still routes to the frontend.
        $this->assertSame(404, $this->fetch('/tools/files/not-an-id')->getStatusCode());
    }
}
