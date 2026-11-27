<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Storage;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Data\DataManager;
use Preflow\Folio\Storage\TableSync;
use Preflow\Folio\Tests\Support\PageLayoutsScaffold;
use Preflow\Publishing\RecordColumns;
use Preflow\Testing\ChecksFrameBalance;

/**
 * The system columns "_layout" and "_areas" (0.92) exist only on the tables of types with
 * "layouts": the type's table and, for a publishing type, its published snapshots (the revisions
 * keep the whole record in their "data" column).
 */
#[\PHPUnit\Framework\Attributes\RequiresPhpExtension('pdo_sqlite')]
final class LayoutColumnsTest extends TestCase
{
    use ChecksFrameBalance;

    use PageLayoutsScaffold;

    protected function setUp(): void
    {
        $this->scaffoldLayouts('sqlite', publishing: true);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_layout_columns_only_on_opted_in_types_and_their_shadows(): void
    {
        $app = $this->app();
        $c = $app->container();
        $registry = $c->get(DataManager::class)->getTypeRegistry();
        $sync = $c->get(TableSync::class);

        $this->assertSame([...TableSync::columns(), ...PageLayouts::COLUMNS], $sync->columnsFor($registry->get('page')));
        $this->assertSame(TableSync::columns(), $sync->columnsFor($registry->get('post')));
        $this->assertSame($sync->columnsFor($registry->get('page')), $c->get(RecordColumns::class)->columns($registry->get('page')), 'the shadows of preflow/publishing get them too');
        $this->assertSame(TableSync::columns(), $c->get(RecordColumns::class)->columns($registry->get('post')));

        $lines = $sync->sync();
        $this->assertContains('created table page__published', $lines);

        $dm = $c->get(DataManager::class);
        $tables = static fn (string $type): array => [$registry->get($type), \Preflow\Publishing\ShadowTypes::published($registry->get($type))];
        foreach ($tables('page') as $def) {
            foreach (PageLayouts::COLUMNS as $column) {
                $this->assertTrue($dm->hasColumn($def, $column), $def->table . '.' . $column);
            }
        }
        foreach ([...$tables('post'), ...$tables('note'), $registry->get('site')] as $def) {
            foreach (PageLayouts::COLUMNS as $column) {
                $this->assertFalse($dm->hasColumn($def, $column), $def->table . '.' . $column);
            }
        }

        // The map stays a map through SQL: stored as JSON text, read back as such and placed.
        $this->page('r1', 'about', 'landing', ['hero' => [self::hero(self::HERO, 'Welcome')], 'main' => []]);
        $stored = $this->stored('page', 'r1');
        $this->assertSame('landing', $stored->get('_layout'));
        $resolved = $c->get(PageLayouts::class)->resolve($stored);
        $this->assertSame(['hero', 'main'], array_keys($resolved?->context()['areas'] ?? []));
        $this->assertSame('Welcome', $resolved?->context()['areas']['hero'][0]['headline']['en'] ?? null);

        // Published, the snapshot holds them.
        $this->publisher()->publish($stored, null);
        $published = $c->get(DataManager::class)->findIn(\Preflow\Publishing\ShadowTypes::published($registry->get('page')), 'r1');
        $this->assertSame('landing', $published?->get('_layout'));
        $this->assertStringContainsString(self::HERO, (string) json_encode($published?->get('_areas')));
    }
}
