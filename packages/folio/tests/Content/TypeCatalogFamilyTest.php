<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Boot\BootManifest;
use Preflow\Data\TypeFamily;
use Preflow\Folio\Content\FrontendConfigException;
use Preflow\Folio\Content\TypeCatalog;

/** "family" in a model file: declared, the defaults, a wrong value, and the boot manifest's round trip. */
final class TypeCatalogFamilyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_family_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.json') ?: []);
        @rmdir($this->dir);
    }

    /** @param array<string, mixed> $schema */
    private function model(string $key, array $schema = []): void
    {
        file_put_contents($this->dir . "/{$key}.json", (string) json_encode(['key' => $key, 'table' => $key, 'fields' => []] + $schema));
    }

    public function test_a_type_declares_its_family(): void
    {
        $this->model('tool', ['family' => 'live']);
        $this->model('product', ['family' => 'shop', 'frontend' => ['route' => '/shop/{slug}']]);

        $catalog = new TypeCatalog($this->dir);

        $this->assertSame(TypeFamily::Live, $catalog->get('tool')?->family);
        $this->assertSame(TypeFamily::Shop, $catalog->get('product')?->family, 'declared wins over the frontend default');
    }

    public function test_the_defaults_pages_members_and_none(): void
    {
        $this->model('page', ['frontend' => ['route' => '/{slug}']]);
        $this->model('member', ['users' => 'members']);
        $this->model('category');

        $catalog = new TypeCatalog($this->dir);

        $this->assertSame(TypeFamily::Page, $catalog->get('page')?->family);
        $this->assertSame(TypeFamily::Member, $catalog->get('member')?->family);
        $this->assertNull($catalog->get('category')?->family, 'nothing said, no tint');
    }

    public function test_a_type_added_at_runtime_declares_it_too(): void
    {
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'gallery', 'family' => 'asset']);

        $this->assertSame(TypeFamily::Asset, $catalog->get('gallery')?->family);
    }

    public function test_an_unknown_family_stops_the_boot(): void
    {
        $this->model('x', ['family' => 'blog']);

        $this->expectException(FrontendConfigException::class);
        $this->expectExceptionMessage('Type "x": "family" must be one of page, reference, asset, member, shop, live');
        (new TypeCatalog($this->dir))->all();
    }

    public function test_a_family_that_is_no_text_is_refused(): void
    {
        $this->model('x', ['family' => null]);

        $this->expectException(FrontendConfigException::class);
        (new TypeCatalog($this->dir))->all();
    }

    public function test_the_boot_manifest_stores_the_value_and_reads_the_family_back(): void
    {
        $this->model('job', ['family' => 'reference']);
        $this->model('page', ['frontend' => ['route' => '/{slug}']]);
        $this->model('category');
        $recording = BootManifest::recording();

        (new TypeCatalog($this->dir, $recording))->all();
        $stored = array_column($recording->recorded()['catalogs']['folio.type_listings'], 'family', 'key');

        $this->assertSame(['category' => null, 'job' => 'reference', 'page' => 'page'], $this->sorted($stored));

        $loaded = new TypeCatalog($this->dir, BootManifest::fromData(['catalogs' => $recording->recorded()['catalogs']]));
        $this->assertSame(TypeFamily::Reference, $loaded->get('job')?->family);
        $this->assertSame(TypeFamily::Page, $loaded->get('page')?->family);
        $this->assertNull($loaded->get('category')?->family);
    }

    public function test_a_manifest_from_before_families_reads_as_none(): void
    {
        $this->model('job', ['family' => 'reference']);
        $recording = BootManifest::recording();
        (new TypeCatalog($this->dir, $recording))->all();
        $catalogs = $recording->recorded()['catalogs'];
        unset($catalogs['folio.type_listings'][0]['family']);

        $this->assertNull((new TypeCatalog($this->dir, BootManifest::fromData(['catalogs' => $catalogs])))->get('job')?->family);
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function sorted(array $map): array
    {
        ksort($map);

        return $map;
    }
}
