<?php

declare(strict_types=1);

namespace Preflow\Publishing\Tests;

use Preflow\Core\Application;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\Publishing\ShadowTypes;

/**
 * `preflow migrate` on a site whose storage is JSON only runs no schema migrations, but it runs
 * the storage-neutral steps: publishing.published_versions marks the snapshots from before 0.77.
 * The table steps stay SQL-only (@since 0.77.1).
 */
final class MigratePublishedVersionsTest extends PublishingAppTestCase
{
    public function test_migrate_on_json_storage_marks_the_published_versions(): void
    {
        $publisher = $this->publisher();
        $type = $this->dm()->getTypeRegistry()->get('post');
        $publisher->publish(DynamicRecord::fromArray($type, ['uuid' => 'a', 'title' => ['en' => 'One']]), 'ana');
        // A snapshot from before 0.77: no _published_version.
        $shadow = ShadowTypes::published($type);
        $data = ($this->dm()->findIn($shadow, 'a') ?? throw new \RuntimeException('no snapshot'))->toArray();
        unset($data['_published_version']);
        $this->dm()->deleteIn($shadow, 'a');
        $this->dm()->saveType(DynamicRecord::fromArray($shadow, $data), false);
        $this->assertNull($this->dm()->findIn($shadow, 'a')?->get('_published_version'));

        [$code, $out] = $this->migrate();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('No SQL storage configured: no schema migrations.', $out);
        $this->assertStringContainsString('[publishing.published_versions] marked 1 published copies of post with their version', $out);
        $this->assertSame(1, (int) $this->dm()->findIn($shadow, 'a')?->get('_published_version'));
        $this->assertStringNotContainsString('[publishing.tables]', $out);

        [, $again] = $this->migrate();
        $this->assertStringNotContainsString('[publishing.published_versions]', $again, 'a second run marks nothing');
    }

    public function test_the_table_steps_are_sql_only_and_the_marks_run_on_any_storage(): void
    {
        $registry = $this->app()->container()->get(MigrationRegistry::class);

        $this->assertTrue($registry->isSqlOnly('publishing.tables'));
        $this->assertFalse($registry->isSqlOnly('publishing.published_versions'));
    }

    /** @return array{int, string} */
    private function migrate(): array
    {
        $app = $this->app();
        ob_start();
        $code = (new MigrateCommand(static fn (): Application => $app))->execute([]);

        return [$code, (string) ob_get_clean()];
    }
}
