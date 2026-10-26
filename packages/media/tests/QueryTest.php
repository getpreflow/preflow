<?php

declare(strict_types=1);

namespace Preflow\Media\Tests;

use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Preflow\Media\Asset;
use Preflow\Media\Image\GdBackend;
use Preflow\Media\Image\VariantGenerator;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;

/** MediaLibrary::query(): kind, search, filters on the declared extra fields, paging. */
#[RequiresPhpExtension('gd')]
#[RequiresPhpExtension('fileinfo')]
#[RequiresFunction('imagewebp')]
final class QueryTest extends TestCase
{
    use MediaFixtures;

    /** @var array<string, Asset> */
    private array $assets = [];
    private MediaLibrary $lib;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->lib = $this->library(
            ['fields' => ExtraFieldsTest::fields() + ['secret' => ['type' => 'select', 'options' => ['x' => 'X']]], 'filters' => ['usage_type', 'game', 'featured'], 'private_dir' => $this->assetRoot . '/private'],
            new VariantGenerator(new GdBackend(), [480], 75),
        );
        // a.png: cover of g1, b.png: step of g1+g2, c.png: cover of g2 and featured, d.pdf: a document
        $this->add('a', 'a.png', ['usage_type' => 'cover', 'game' => ['g1'], 'title' => 'Alpha cover', 'alt' => ['en' => 'A red barn']]);
        $this->add('b', 'b.png', ['usage_type' => 'step', 'game' => ['g1', 'g2'], 'title' => 'Beta']);
        $this->add('c', 'c.png', ['usage_type' => 'cover', 'game' => ['g2'], 'featured' => true, 'secret' => 'x']);
        $this->add('d', 'rules.pdf', ['title' => 'Rules']);
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    /** @param array<string, mixed> $meta */
    private function add(string $key, string $name, array $meta): void
    {
        $bytes = str_ends_with($name, '.pdf') ? "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n" : $this->png(40, 30);
        $asset = $this->lib->upload($this->upload($bytes, $name), null);
        $this->assets[$key] = $this->lib->update($asset->id, $meta);
    }

    /** @return list<string> */
    private function keys(\Preflow\Media\AssetPage $page): array
    {
        $keys = array_map(static fn (Asset $a) => $a->id, $this->assets);
        $ids = array_map(static fn (Asset $a) => array_search($a->id, $keys, true), $page->items);
        sort($ids);

        return $ids;
    }

    public function test_no_arguments_lists_everything(): void
    {
        $page = $this->lib->query();

        $this->assertSame(4, $page->total);
        $this->assertSame(1, $page->page);
        $this->assertSame(50, $page->perPage);
        $this->assertSame(['a', 'b', 'c', 'd'], $this->keys($page));
        $this->assertContainsOnlyInstancesOf(Asset::class, $page->items);
    }

    public function test_a_select_filter_compares_by_equality(): void
    {
        $this->assertSame(['a', 'c'], $this->keys($this->lib->query(filters: ['usage_type' => 'cover'])));
    }

    public function test_a_relation_filter_matches_assets_whose_field_contains_the_id(): void
    {
        $this->assertSame(['a', 'b'], $this->keys($this->lib->query(filters: ['game' => 'g1'])));
        $this->assertSame(['b', 'c'], $this->keys($this->lib->query(filters: ['game' => ['g2']])));
        $this->assertSame(['a', 'b', 'c'], $this->keys($this->lib->query(filters: ['game' => ['g1', 'g2']]))); // any of them
    }

    public function test_a_boolean_filter(): void
    {
        $this->assertSame(['c'], $this->keys($this->lib->query(filters: ['featured' => true])));
        $this->assertSame(['c'], $this->keys($this->lib->query(filters: ['featured' => '1'])));
        $this->assertSame(['a', 'b', 'd'], $this->keys($this->lib->query(filters: ['featured' => false])));
    }

    public function test_the_private_flag_filters_like_a_boolean_without_being_declared(): void
    {
        $secret = $this->lib->upload($this->upload($this->png(10, 10), 'secret.png'), null, private: true);
        $ids = static fn (\Preflow\Media\AssetPage $page) => array_map(static fn (Asset $a) => $a->id, $page->items);

        $this->assertSame([$secret->id], $ids($this->lib->query(filters: ['private' => true])));
        $this->assertNotContains($secret->id, $ids($this->lib->query(filters: ['private' => false])));
        $this->assertSame(4, $this->lib->query(filters: ['private' => '0'])->total);
        $this->assertSame(5, $this->lib->query(filters: ['private' => ''])->total);
    }

    public function test_filters_combine(): void
    {
        $this->assertSame(['a'], $this->keys($this->lib->query(filters: ['usage_type' => 'cover', 'game' => 'g1'])));
    }

    public function test_unknown_or_undeclared_filters_are_ignored(): void
    {
        $this->assertSame(['a', 'b', 'c', 'd'], $this->keys($this->lib->query(filters: ['nonsense' => 'x', 'path' => 'a', 'secret' => 'x'])));
    }

    public function test_empty_filter_values_are_ignored(): void
    {
        $this->assertSame(['a', 'b', 'c', 'd'], $this->keys($this->lib->query(filters: ['usage_type' => '', 'game' => []])));
    }

    public function test_kind(): void
    {
        $this->assertSame(['a', 'b', 'c'], $this->keys($this->lib->query(kind: 'image')));
        $this->assertSame(['d'], $this->keys($this->lib->query(kind: 'document')));
        $this->assertSame([], $this->keys($this->lib->query(kind: 'video')));
    }

    public function test_search_looks_at_name_title_and_alt(): void
    {
        $this->assertSame(['d'], $this->keys($this->lib->query(search: 'rules')));    // original name and title
        $this->assertSame(['b'], $this->keys($this->lib->query(search: 'beta')));     // title
        $this->assertSame(['c'], $this->keys($this->lib->query(search: 'c.png')));    // original name
        $this->assertSame(['a'], $this->keys($this->lib->query(search: 'red barn'))); // alt, every word
        $this->assertSame([], $this->keys($this->lib->query(search: 'red zebra')));
    }

    public function test_search_does_not_look_at_other_columns(): void
    {
        $this->assertSame([], $this->keys($this->lib->query(search: 'image/png')));
        $this->assertSame([], $this->keys($this->lib->query(search: 'cover', filters: ['usage_type' => 'step'])));
    }

    public function test_everything_combines(): void
    {
        $this->assertSame(['a'], $this->keys($this->lib->query('image', 'alpha', ['usage_type' => 'cover'])));
        $this->assertSame([], $this->keys($this->lib->query('document', 'alpha')));
    }

    public function test_paging_keeps_the_total_and_newest_first(): void
    {
        $all = array_map(fn (Asset $a) => $a->id, $this->lib->all());

        $first = $this->lib->query(perPage: 3);
        $second = $this->lib->query(page: 2, perPage: 3);

        $this->assertSame(4, $first->total);
        $this->assertSame(4, $second->total);
        $this->assertSame(array_slice($all, 0, 3), array_map(fn (Asset $a) => $a->id, $first->items));
        $this->assertSame(array_slice($all, 3), array_map(fn (Asset $a) => $a->id, $second->items));
        $this->assertSame(2, $second->page);
        $this->assertSame(3, $second->perPage);
    }

    public function test_paging_with_a_filter_counts_the_filtered_total(): void
    {
        $page = $this->lib->query(filters: ['usage_type' => 'cover'], perPage: 1);

        $this->assertCount(1, $page->items);
        $this->assertSame(2, $page->total);
    }

    public function test_a_page_past_the_end_gives_the_last_page_and_bad_numbers_are_clamped(): void
    {
        $past = $this->lib->query(page: 99, perPage: 3);
        $this->assertSame(2, $past->page);
        $this->assertCount(1, $past->items);

        $bad = $this->lib->query(page: 0, perPage: 0);
        $this->assertSame(1, $bad->page);
        $this->assertSame(1, $bad->perPage);
        $this->assertCount(1, $bad->items);
        $this->assertSame(4, $bad->total);
    }

    public function test_a_search_is_not_an_injection_vector(): void
    {
        $this->assertSame([], $this->keys($this->lib->query(search: "' OR 1=1 --")));
    }

    public function test_search_works_when_the_data_manager_has_a_type_registry_that_does_not_know_the_asset_type(): void
    {
        // As on a site: the asset type is code-defined, so no models/_asset.json exists.
        $models = $this->assetRoot . '/models';
        mkdir($models);
        $dm = new \Preflow\Data\DataManager(
            drivers: ['json' => new \Preflow\Data\Driver\JsonFileDriver($this->assetRoot . '/store')],
            defaultDriver: 'json',
            typeRegistry: new \Preflow\Data\TypeRegistry($models),
        );
        $lib = new MediaLibrary($dm, new \Preflow\Data\Locales(['en', 'de'], 'en'), $this->assetRoot . '/media', '/media', ['fields' => ExtraFieldsTest::fields(), 'filters' => ['game']]);

        $this->assertSame(['d'], $this->keys($lib->query(search: 'rules')));
        $this->assertSame(['a', 'b'], $this->keys($lib->query(filters: ['game' => 'g1'])));
    }

    public function test_a_boolean_false_filter_includes_assets_from_before_the_field_existed(): void
    {
        // Same storage, first without the fields: this asset has no featured value at all.
        $old = $this->library()->upload($this->upload($this->png(40, 30), 'old.png'), null);

        $false = array_map(static fn (Asset $a) => $a->id, $this->lib->query(filters: ['featured' => false])->items);
        $true = array_map(static fn (Asset $a) => $a->id, $this->lib->query(filters: ['featured' => true])->items);

        $this->assertContains($old->id, $false);
        $this->assertNotContains($old->id, $true);
        $this->assertNotContains($this->assets['c']->id, $false);
        $this->assertCount(5, $this->lib->query()->items);
    }
}
