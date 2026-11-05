<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Audit\AuditTargets;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\View\Audit\AuditRegistry;
use Preflow\View\Audit\Target;

/**
 * Where to fix an SEO audit finding: Folio tells the audit's registry which record a page
 * shows (FrontendResolver) and which asset a media file belongs to (its path). The scaffold's
 * "post" type lives at "/{slug}" in en and de (prefixed).
 */
final class AuditTargetsTest extends TestCase
{
    use PublishingScaffold;

    private const string ASSET = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function registry(): AuditRegistry
    {
        return $this->app()->container()->get(AuditRegistry::class);
    }

    private function publishPost(string $id, array $title, array $slug): void
    {
        $this->publisher()->publish($this->working('post', $id, ['title' => $title, 'slug' => $slug, '_locales' => array_keys($slug)]), null);
    }

    public function test_a_page_is_its_record_in_every_locale(): void
    {
        $this->publishPost('p1', ['en' => 'Hello', 'de' => 'Hallo'], ['en' => 'hello', 'de' => 'hallo']);
        $registry = $this->registry();

        $this->assertEquals(new Target('record', 'post', 'p1'), $registry->target('http://localhost/hello', 'en'));
        $this->assertEquals(new Target('record', 'post', 'p1'), $registry->target('http://localhost/de/hallo', 'de'));
        $this->assertEquals(new Target('record', 'post', 'p1'), $registry->target('/hello', ''), 'a path, and a finding without a locale');
    }

    public function test_an_encoded_path_finds_its_page(): void
    {
        // Folio's slug rule keeps umlauts out of new addresses; older records may still have them.
        $this->writeModel('news', ['label' => 'News', 'publishing' => false, 'frontend' => ['route' => '/news/{slug}'], 'fields' => [
            'title' => ['type' => 'string'],
            'slug' => ['type' => 'string'],
            'status' => ['type' => 'string'],
        ]]);
        $this->working('news', 'n1', ['title' => 'About', 'slug' => 'über-uns', 'status' => 'published']);

        $this->assertEquals(new Target('record', 'news', 'n1'), $this->registry()->target('http://localhost/news/%C3%BCber-uns', 'en'));
    }

    public function test_an_address_without_a_page_has_no_target(): void
    {
        $this->publishPost('p1', ['en' => 'Hello'], ['en' => 'hello']);
        $registry = $this->registry();

        $this->assertNull($registry->target('http://localhost/nope', 'en'));
        $this->assertNull($registry->target('http://localhost/de/hello', 'de'), 'not visible in German');
        $this->assertNull($registry->target('', ''));
    }

    public function test_a_media_file_is_its_asset(): void
    {
        $registry = $this->registry();
        $asset = new Target('asset', 'asset', self::ASSET);

        $this->assertEquals($asset, $registry->target('http://localhost/media/2026/11/' . self::ASSET . '.jpg', ''));
        $this->assertEquals($asset, $registry->target('/media/2026/11/' . self::ASSET . '-800.webp', 'en'));
        $this->assertEquals($asset, $registry->target('/media/2026/11/' . self::ASSET . '-v2-edit-ab12-1200.avif', 'en'));
        $this->assertNull($registry->target('/media/2026/11/not-an-asset.jpg', ''));
        $this->assertNull($registry->target('/media/' . self::ASSET . '.jpg', ''));
    }

    public function test_the_media_url_comes_from_the_media_config(): void
    {
        $this->write('config/media.php', "<?php\nreturn ['url' => '/files-public'];\n");

        $this->assertEquals(new Target('asset', 'asset', self::ASSET), $this->registry()->target('/files-public/2026/11/' . self::ASSET . '-400.webp', ''));
        $this->assertNull($this->registry()->target('/media/2026/11/' . self::ASSET . '-400.webp', ''));
    }

    public function test_a_failing_lookup_is_no_target(): void
    {
        $targets = new AuditTargets(
            static fn () => throw new \RuntimeException('storage down'),
            static fn (): string => '/media',
            'en',
        );

        $this->assertNull($targets->target('http://localhost/hello', 'en'));
        $this->assertEquals(new Target('asset', 'asset', self::ASSET), $targets->target('/media/2026/11/' . self::ASSET . '.png', ''));
    }
}
