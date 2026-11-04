<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\PermissionRegistry;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Folio\Permissions\KnownPermissions;
use Preflow\Folio\Tests\Support\InternalStorage;

/** Which declared permissions are new since the site last looked (folio_known_permissions), on JSON and on SQLite. */
final class KnownPermissionsTest extends TestCase
{
    use InternalStorage;

    protected function tearDown(): void
    {
        $this->removeInternalStorage();
    }

    private static function registry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->declare('page.view', 'View', 'Pages');
        $registry->declare('redirects.manage', 'Redirects', 'Folio');

        return $registry;
    }

    /** @return list<string> */
    private static function stored(DataManager $dm, KnownPermissions $known): array
    {
        $names = array_map(static fn ($row): string => (string) $row->get('name'), $dm->queryIn($known->definition())->get()->items());
        sort($names);

        return $names;
    }

    #[DataProvider('storages')]
    public function test_first_run_knows_everything_but_the_announced_ones(string $storage): void
    {
        $dm = $this->internalStorage($storage, KnownPermissions::definition());
        $known = new KnownPermissions($dm, self::registry());

        $this->assertSame(['redirects.manage'], $known->fresh());
        $this->assertSame(['page.view'], self::stored($dm, $known), 'the baseline');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $dm->findIn($known->definition(), 'page.view')?->get('known_at'));
        $this->assertSame(['redirects.manage'], $known->fresh(), 'asking again changes nothing');
        $this->assertSame(['page.view'], self::stored($dm, $known));
    }

    #[DataProvider('storages')]
    public function test_a_later_declaration_is_new_until_marked_known(string $storage): void
    {
        $dm = $this->internalStorage($storage, KnownPermissions::definition());
        $this->assertSame(['redirects.manage'], (new KnownPermissions($dm, self::registry()))->fresh());

        $registry = self::registry();
        $registry->declare('events.view', 'View', 'Events');
        $known = new KnownPermissions($dm, $registry);
        $this->assertSame(['events.view', 'redirects.manage'], $known->fresh());

        $known->markKnown(['redirects.manage']);
        $this->assertSame(['events.view'], $known->fresh());
        $this->assertSame(['events.view'], (new KnownPermissions($dm, $registry))->fresh(), 'known for good');
    }

    #[DataProvider('storages')]
    public function test_a_disabled_permission_is_new_once_enabled(string $storage): void
    {
        $dm = $this->internalStorage($storage, KnownPermissions::definition());
        $registry = self::registry();
        $registry->declare('members.view', 'View', 'Members');
        $registry->disable('members.view', 'extension members');
        $known = new KnownPermissions($dm, $registry);

        $this->assertSame(['redirects.manage'], $known->fresh());
        $this->assertNotContains('members.view', self::stored($dm, $known), 'not part of the baseline either');

        // The extension switched on: the next boot declares it without disabling it.
        $enabled = self::registry();
        $enabled->declare('members.view', 'View', 'Members');
        $this->assertSame(['members.view', 'redirects.manage'], (new KnownPermissions($dm, $enabled))->fresh());
    }

    #[DataProvider('storages')]
    public function test_migrate_lines(string $storage): void
    {
        $dm = $this->internalStorage($storage, KnownPermissions::definition());
        $registry = self::registry();
        $known = new KnownPermissions($dm, $registry);

        $this->assertSame(['New permissions to review in Settings → Roles: redirects.manage (Redirects)'], $known->migrateLines());

        $registry->declare('events.view', 'View', 'Events');
        $this->assertSame(['New permissions to review in Settings → Roles: events.view (View), redirects.manage (Redirects)'], $known->migrateLines());

        $known->markKnown(['events.view', 'redirects.manage']);
        $this->assertSame([], $known->migrateLines());
    }

    public function test_without_the_table_it_throws_and_writes_nothing(): void
    {
        // A site upgraded but not migrated yet: callers on GET requests swallow the error.
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $known = new KnownPermissions(new DataManager(drivers: ['default' => new SqliteDriver($pdo)]), self::registry());

        $thrown = false;
        try {
            $known->fresh();
        } catch (\Throwable) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'the missing table throws');
        $this->assertSame([], $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN));
    }
}
