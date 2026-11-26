<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\LayoutReport;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\TypeRegistry;

/** The page layout report: what `preflow migrate` and `db:check` print about layouts, read through DataManager. */
final class LayoutReportTest extends BlocksAppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn ['layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class]];\n");
        $this->model('page', ['allowed' => ['promo', 'standard', 'gone'], 'default' => 'standard', 'pinned' => ['start' => 'promo']]);
        $this->model('note', null);
    }

    /** @param array<string, mixed>|null $layouts */
    private function model(string $key, ?array $layouts): void
    {
        $this->write('config/models/' . $key . '.json', (string) json_encode([
            'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
        ] + ($layouts === null ? [] : ['layouts' => $layouts])));
    }

    /** @param array<string, mixed> $values */
    private function page(string $id, array $values, string $type = 'page'): void
    {
        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get($type), ['uuid' => $id] + $values), validate: false);
    }

    private function report(int $pageSize = 100, int $named = 20): LayoutReport
    {
        $container = $this->app()->container();

        return new LayoutReport($container->get(PageLayouts::class), $container->get(LayoutRegistry::class), $this->types(), $this->data(), $pageSize, $named);
    }

    public function test_report_names_pages_on_a_missing_layout(): void
    {
        $this->page('p1', ['slug' => 'about', '_layout' => 'gone']);
        $this->page('p2', ['slug' => 'team', '_layout' => 'gone']);
        $this->page('p3', ['slug' => 'blog', '_layout' => 'retired']);
        $this->page('p4', ['slug' => 'fine', '_layout' => 'promo']);
        $this->page('p5', ['slug' => 'start', '_layout' => 'retired']); // pinned: the pin decides, the stored key is moot

        $lines = $this->report()->lines();

        $this->assertContains('Type "page": layouts.allowed names "gone", which no #[Layout] class declares. Its pages show as "standard" until you add the class or remove the key.', $lines);
        $this->assertContains('Type "page": 2 pages use the layout "gone", which is not available (they show as "standard"): about (p1), team (p2).', $lines);
        $this->assertContains('Type "page": 1 page uses the layout "retired", which is not available (they show as "standard"): blog (p3).', $lines);
        $this->assertCount(3, $lines);
    }

    public function test_report_caps_the_names_and_reads_in_pages(): void
    {
        foreach (range(1, 7) as $n) {
            $this->page('p' . $n, ['slug' => 's' . $n, '_layout' => 'retired']);
        }

        $lines = $this->report(pageSize: 2, named: 3)->lines();

        $this->assertContains('Type "page": 7 pages use the layout "retired", which is not available (they show as "standard"): s1 (p1), s2 (p2), s3 (p3) and 4 more.', $lines);
    }

    public function test_report_names_pins_without_a_page(): void
    {
        $this->assertContains('Type "page": layouts.pinned "start" names no page with that slug yet. The pin applies once the page exists.', $this->report()->lines());

        $this->page('p1', ['slug' => 'start']);

        $this->assertNotContains('Type "page": layouts.pinned "start" names no page with that slug yet. The pin applies once the page exists.', $this->report()->lines());
    }

    public function test_report_names_pages_with_unplaced_areas(): void
    {
        $block = static fn (string $uid): array => ['_uid' => $uid, '_block' => 'quote'];
        $this->page('p1', ['slug' => 'about', '_layout' => 'standard', '_areas' => ['hero' => [$block('a')], 'main' => [$block('b')]]]);
        $this->page('p2', ['slug' => 'team', '_layout' => 'promo', '_areas' => json_encode(['hero' => [], 'main' => [$block('c')], 'side' => [$block('d')]])]);
        $this->page('p3', ['slug' => 'blog', '_layout' => 'standard', '_areas' => ['hero' => []]]);
        $this->page('p4', ['slug' => 'plain', '_layout' => 'standard']);

        $lines = $this->report()->lines();

        $this->assertContains('Type "page": 2 pages have blocks in areas their layout does not show (hero, side): about (p1), team (p2). Open them in the editor ("Not shown in this layout").', $lines);
    }

    public function test_report_is_empty_without_layouts(): void
    {
        $this->write('config/models/page.json', (string) json_encode(['key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'fields' => ['title' => ['type' => 'string']]]));
        $this->page('n1', ['slug' => 'x', '_layout' => 'gone'], 'note');

        $this->assertSame([], $this->report()->lines());
    }

    public function test_the_report_is_registered_as_a_warning_for_any_storage(): void
    {
        $this->page('p1', ['slug' => 'about', '_layout' => 'retired']);

        $warnings = $this->app()->container()->get(MigrationRegistry::class)->warnings(sql: false);

        $this->assertArrayHasKey('blocks.layouts', $warnings);
        $this->assertContains('Type "page": 1 page uses the layout "retired", which is not available (they show as "standard"): about (p1).', $warnings['blocks.layouts']());
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }
}
