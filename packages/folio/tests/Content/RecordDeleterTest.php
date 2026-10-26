<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\RecordDeleter;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Extension\AfterSave;
use Preflow\Folio\Extension\BeforeDelete;
use Preflow\Folio\Extension\BeforeSave;
use Preflow\Folio\Extension\Customization\Refused;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationResult;
use Preflow\Validation\ValidatorFactory;

/**
 * designer <- game (cascade), designer <- page.designers (detach, multiple),
 * designer <- progress (restrict), designer <- review (restrict, publishing),
 * game <- page.games (detach, multiple). Pages publish.
 */
final class RecordDeleterTest extends TestCase
{
    private string $dir;
    private DataManager $dm;
    private TypeRegistry $registry;
    private TypeCatalog $catalog;
    private Publisher $publisher;

    /** @var list<string> */
    private array $log = [];

    /** @var array<string, \Closure(DynamicRecord): void> hook class => extra behaviour */
    private array $behaviour = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_deleter_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($rii as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param 'json'|'sqlite' $storage */
    private function boot(string $storage = 'json', bool $publishedTables = true): void
    {
        $models = [
            'designer' => ['label' => 'Designers', 'fields' => ['name' => ['type' => 'string']]],
            'game' => ['label' => 'Games', 'fields' => [
                'title' => ['type' => 'string'],
                'designer' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'onDelete' => 'cascade']],
            ]],
            'page' => ['label' => 'Pages', 'publishing' => true, 'fields' => [
                'title' => ['type' => 'string'],
                'games' => ['type' => 'relation', 'relation' => ['to' => 'game', 'multiple' => true]],
                'designers' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'multiple' => true]],
            ]],
            'progress' => ['label' => 'Progress records', 'fields' => [
                'designer' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'onDelete' => 'restrict']],
            ]],
            'review' => ['label' => 'Reviews', 'publishing' => true, 'fields' => [
                'designer' => ['type' => 'relation', 'relation' => ['to' => 'designer', 'onDelete' => 'restrict']],
            ]],
        ];
        $pdo = null;
        if ($storage === 'sqlite') {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }
        foreach ($models as $key => $model) {
            file_put_contents($this->dir . "/models/{$key}.json", json_encode(
                ['key' => $key, 'table' => $key, 'storage' => $storage, 'id_field' => 'uuid'] + $model,
            ));
            if ($pdo !== null) {
                $tables = [$key];
                if (($model['publishing'] ?? false) && $publishedTables) {
                    $tables[] = $key . '__published';
                    $tables[] = $key . '__revisions';
                }
                foreach ($tables as $table) {
                    $columns = str_ends_with($table, '__revisions')
                        ? ['record_id', 'seq', 'data', 'changed', 'created_at', 'created_by']
                        : [...array_keys($model['fields']), '_version', '_publish_at', '_share_token', '_published_at', '_published_by', '_order', '_locales'];
                    $pdo->exec("CREATE TABLE {$table} (uuid TEXT PRIMARY KEY" . implode('', array_map(static fn ($c) => ", {$c} TEXT", $columns)) . ')');
                }
            }
        }

        $this->registry = new TypeRegistry($this->dir . '/models');
        $drivers = ['json' => new JsonFileDriver($this->dir . '/store')];
        if ($pdo !== null) {
            $drivers['sqlite'] = new SqliteDriver($pdo);
        }
        $this->dm = new DataManager(
            drivers: $drivers,
            defaultDriver: 'json',
            typeRegistry: $this->registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $this->catalog = new TypeCatalog($this->dir . '/models');
        $fieldTypes = new FieldTypeRegistry();
        $fieldTypes->register(new StringFieldType());
        $this->publisher = new Publisher($this->dm, $this->catalog, new RecordValidator($fieldTypes, $this->dm));
    }

    private function deleter(): RecordDeleter
    {
        return new RecordDeleter($this->dm, $this->catalog, $this->publisher, function (string $hook, DynamicRecord $record): void {
            $short = substr($hook, (int) strrpos($hook, '\\') + 1);
            $this->log[] = $short . ':' . $record->getType()->key . ':' . $record->getId();
            if (isset($this->behaviour[$hook])) {
                ($this->behaviour[$hook])($record);
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function seed(string $type, string $id, array $data = []): void
    {
        $this->dm->saveType(DynamicRecord::fromArray($this->registry->get($type), ['uuid' => $id] + $data), false);
    }

    private function seedWorld(): void
    {
        $this->seed('designer', 'd1', ['name' => 'Uwe']);
        $this->seed('designer', 'd2', ['name' => 'Reiner']);
        $this->seed('game', 'g1', ['title' => 'Agricola', 'designer' => 'd1']);
        $this->seed('game', 'g2', ['title' => 'Caverna', 'designer' => 'd1']);
        $this->seed('game', 'g3', ['title' => 'Lost Cities', 'designer' => 'd2']);
        $this->seed('page', 'p1', ['title' => 'Best of', 'games' => '["g1","g3"]', 'designers' => '["d2"]']);
        $this->seed('page', 'p2', ['title' => 'Uwe', 'games' => '[]', 'designers' => '["d1","d2"]']);
    }

    /** @return array<string, mixed>|null */
    private function stored(string $type, string $id): ?array
    {
        return $this->dm->findType($type, $id)?->toArray();
    }

    public function test_delete_cascades_detaches_and_runs_hooks_in_order(): void
    {
        $this->boot();
        $this->seedWorld();

        $this->deleter()->delete('designer', ['d1']);

        $this->assertNull($this->stored('designer', 'd1'));
        $this->assertNull($this->stored('game', 'g1'));
        $this->assertNull($this->stored('game', 'g2'));
        $this->assertNotNull($this->stored('game', 'g3'));
        $this->assertSame('["g3"]', $this->stored('page', 'p1')['games']);
        $this->assertSame('["d2"]', $this->stored('page', 'p2')['designers']);

        // Plan order: pointers of the designer first, then of its games.
        $this->assertSame([
            'BeforeSave:page:p2', 'BeforeSave:page:p1',
            'BeforeDelete:designer:d1', 'BeforeDelete:game:g1', 'BeforeDelete:game:g2',
            'AfterSave:page:p2', 'AfterSave:page:p1',
            'AfterDelete:designer:d1', 'AfterDelete:game:g1', 'AfterDelete:game:g2',
        ], $this->log);
    }

    public function test_before_save_sees_the_id_already_removed(): void
    {
        $this->boot();
        $this->seedWorld();
        $seen = [];
        $this->behaviour[BeforeSave::class] = static function (DynamicRecord $r) use (&$seen): void {
            $seen[$r->getId()] = [$r->get('games'), $r->get('designers')];
        };

        $this->deleter()->delete('designer', ['d1']);

        $this->assertSame(['["g3"]', '["d2"]'], $seen['p1']);
        $this->assertSame(['[]', '["d2"]'], $seen['p2']);
    }

    public function test_refusal_from_a_cascaded_record_leaves_everything_in_place(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->behaviour[BeforeDelete::class] = static function (DynamicRecord $r): void {
            if ($r->getId() === 'g2') {
                throw new Refused('Caverna is in a tournament.');
            }
        };

        try {
            $this->deleter()->delete('designer', ['d1']);
            $this->fail('Expected Refused');
        } catch (Refused $e) {
            $this->assertSame('Caverna is in a tournament.', $e->getMessage());
        }

        $this->assertNotNull($this->stored('designer', 'd1'));
        $this->assertNotNull($this->stored('game', 'g1'));
        $this->assertNotNull($this->stored('game', 'g2'));
        $this->assertSame('["g1","g3"]', $this->stored('page', 'p1')['games']);
        $this->assertSame('["d1","d2"]', $this->stored('page', 'p2')['designers']);
        $this->assertSame([], array_values(array_filter($this->log, static fn (string $l) => str_starts_with($l, 'After'))));
    }

    public function test_bulk_delete_where_one_cascaded_record_refuses_deletes_nothing(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->behaviour[BeforeDelete::class] = static function (DynamicRecord $r): void {
            if ($r->getId() === 'g3') {
                throw new Refused('Lost Cities stays.');
            }
        };

        $this->expectException(Refused::class);
        try {
            $this->deleter()->delete('designer', ['d1', 'd2']);
        } finally {
            foreach (['d1', 'd2'] as $id) {
                $this->assertNotNull($this->stored('designer', $id));
            }
            foreach (['g1', 'g2', 'g3'] as $id) {
                $this->assertNotNull($this->stored('game', $id));
            }
        }
    }

    public function test_validation_refusal_of_a_detached_record_cancels_everything(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->behaviour[BeforeSave::class] = static function (DynamicRecord $r): void {
            if ($r->getId() === 'p2') {
                throw new ValidationException(new ValidationResult(['designers' => ['A page needs two designers.']]));
            }
        };

        try {
            $this->deleter()->delete('designer', ['d1']);
            $this->fail('Expected Refused');
        } catch (Refused $e) {
            $this->assertStringContainsString('A page needs two designers.', $e->getMessage());
        }

        $this->assertNotNull($this->stored('designer', 'd1'));
        $this->assertNotNull($this->stored('game', 'g1'));
        $this->assertSame('["g1","g3"]', $this->stored('page', 'p1')['games']);
        $this->assertSame('["d1","d2"]', $this->stored('page', 'p2')['designers']);
    }

    public function test_restricted_plan_is_refused_before_any_hook(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->seed('progress', 'x1', ['designer' => 'd1']);
        $this->seed('progress', 'x2', ['designer' => 'd1']);

        try {
            $this->deleter()->delete('designer', ['d1']);
            $this->fail('Expected Refused');
        } catch (Refused $e) {
            $this->assertSame("Can't delete: 2 progress records still point here.", $e->getMessage());
        }
        $this->assertSame([], $this->log);
        $this->assertNotNull($this->stored('designer', 'd1'));
    }

    public function test_restrict_message_for_one_record_is_singular(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->seed('progress', 'x1', ['designer' => 'd1']);

        $this->expectExceptionMessage("Can't delete: 1 progress record still points here.");
        $this->deleter()->delete('designer', ['d1']);
    }

    public function test_restrict_counts_a_link_that_exists_only_in_the_published_snapshot(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->seed('review', 'r1', ['designer' => 'd1']);
        $this->publisher->publish($this->dm->findType('review', 'r1'), null);
        // The working copy no longer links; the live site still does.
        $working = $this->dm->findType('review', 'r1');
        $working->set('designer', '');
        $this->dm->saveType($working, false);

        $plan = $this->deleter()->plan('designer', ['d1']);
        $this->assertSame(['review' => 1], $plan->restrictions());

        $this->expectExceptionMessage("Can't delete: 1 review still points here.");
        $this->deleter()->delete('designer', ['d1']);
    }

    public function test_detach_on_a_publishing_type_changes_the_working_copy_only(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->publisher->publish($this->dm->findType('page', 'p1'), null);
        $version = (int) $this->dm->findType('page', 'p1')->get('_version');

        $this->deleter()->delete('game', ['g1']);

        $working = $this->dm->findType('page', 'p1');
        $this->assertSame('["g3"]', $working->get('games'));
        $this->assertSame($version + 1, (int) $working->get('_version'));
        $this->assertSame('["g1","g3"]', $this->publisher->snapshot('page', 'p1')?->get('games'));
    }

    public function test_deleting_a_publishing_record_forgets_its_snapshot(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->publisher->publish($this->dm->findType('page', 'p1'), null);

        $this->deleter()->delete('page', ['p1']);

        $this->assertNull($this->stored('page', 'p1'));
        $this->assertNull($this->publisher->snapshot('page', 'p1'));
        $this->assertSame([], $this->publisher->revisions('page', 'p1'));
    }

    public function test_summary_lines(): void
    {
        $this->boot();
        $this->seedWorld();

        $summary = $this->deleter()->summary($this->deleter()->plan('designer', ['d1']));

        $this->assertFalse($summary['restricted']);
        $this->assertSame(['Also deletes 2 games.', '2 pages lose a link.'], $summary['lines']);
        $this->assertSame("This can't be undone.\nAlso deletes 2 games.\n2 pages lose a link.", $summary['confirm']);

        $one = $this->deleter()->summary($this->deleter()->plan('game', ['g1']));
        $this->assertSame(['1 page loses a link.'], $one['lines']);

        $plain = $this->deleter()->summary($this->deleter()->plan('page', ['p2']));
        $this->assertSame([], $plain['lines']);
        $this->assertSame("This can't be undone.", $plain['confirm']);
    }

    public function test_summary_uses_the_singular_type_label_in_the_users_language(): void
    {
        $this->boot();
        $this->seedWorld();
        // page: a label map with a singular map in the model file; game: the site's German file.
        $page = json_decode((string) file_get_contents($this->dir . '/models/page.json'), true);
        $page['label'] = ['en' => 'Pages', 'de' => 'Seiten'];
        $page['label_singular'] = ['en' => 'Page', 'de' => 'Seite'];
        file_put_contents($this->dir . '/models/page.json', json_encode($page));
        $this->catalog = new TypeCatalog($this->dir . '/models');
        mkdir($this->dir . '/lang/de', 0777, true);
        file_put_contents($this->dir . '/lang/de/site.php', "<?php return ['types' => ['game' => ['label' => 'Spiele', 'singular' => 'Spiel']]];");
        $translator = new \Preflow\I18n\Translator($this->dir . '/lang', 'de', 'en');
        $translator->addDefaults('folio', dirname(__DIR__, 2) . '/lang');
        $previous = \Preflow\Folio\FolioText::use($translator);
        try {
            $this->assertSame(['Löscht auch 2 Spiele.', '2 Seiten verlieren eine Verknüpfung.'], $this->deleter()->summary($this->deleter()->plan('designer', ['d1']))['lines']);
            $this->assertSame(['1 Seite verliert eine Verknüpfung.'], $this->deleter()->summary($this->deleter()->plan('game', ['g1']))['lines']);
            $this->seed('game', 'g4', ['title' => 'Keltis', 'designer' => 'd1']);
            $translator->setLocale('fr');
            // No French file: the map's default entry and the plain label, for which French has no singular guess.
            $this->assertSame(['Supprime aussi 3 games.', '2 pages perdent un lien.'], $this->deleter()->summary($this->deleter()->plan('designer', ['d1']))['lines']);
            $this->assertSame(['1 page perd un lien.'], $this->deleter()->summary($this->deleter()->plan('game', ['g1']))['lines']);
        } finally {
            \Preflow\Folio\FolioText::use($previous);
        }
    }

    public function test_summary_of_a_restricted_plan(): void
    {
        $this->boot();
        $this->seedWorld();
        $this->seed('progress', 'x1', ['designer' => 'd1']);
        $this->seed('progress', 'x2', ['designer' => 'd1']);

        $summary = $this->deleter()->summary($this->deleter()->plan('designer', ['d1']));

        $this->assertTrue($summary['restricted']);
        $this->assertSame(["Can't delete: 2 progress records still point here."], $summary['lines']);
        $this->assertSame("Can't delete: 2 progress records still point here.", $summary['confirm']);
    }

    public function test_sqlite_delete_runs_through_and_publishing_without_published_table_plans(): void
    {
        $this->boot('sqlite', publishedTables: false);
        $this->seedWorld();
        $this->seed('review', 'r1', ['designer' => 'd2']);

        // No review__published table yet (migrate not run): the plan still works.
        $plan = $this->deleter()->plan('designer', ['d2']);
        $this->assertSame(['review' => 1], $plan->restrictions());
    }

    public function test_sqlite_delete_commits(): void
    {
        $this->boot('sqlite');
        $this->seedWorld();

        $this->deleter()->delete('designer', ['d1']);

        $this->assertNull($this->stored('designer', 'd1'));
        $this->assertNull($this->stored('game', 'g2'));
        $this->assertSame('["g3"]', $this->stored('page', 'p1')['games']);
        $this->assertContains('AfterSave:page:p2', $this->log);
    }

    public function test_a_failing_write_on_sql_rolls_back(): void
    {
        $this->boot('sqlite');
        $this->seedWorld();
        // The last BeforeDelete (end of the pre-phase) moves the games table away, so the
        // writes fail at the cascaded deletes, after the detaches and the root delete ran.
        $pdo = $this->dm->driver('sqlite')->pdo();
        $calls = 0;
        $this->behaviour[BeforeDelete::class] = static function () use (&$calls, $pdo): void {
            if (++$calls === 3) {
                $pdo->exec('ALTER TABLE game RENAME TO game_away');
            }
        };

        try {
            $this->deleter()->delete('designer', ['d1']);
            $this->fail('Expected a failure');
        } catch (\PDOException) {
        }
        $pdo->exec('ALTER TABLE game_away RENAME TO game');

        $this->assertNotNull($this->stored('designer', 'd1'));
        $this->assertNotNull($this->stored('game', 'g1'));
        $this->assertSame('["g1","g3"]', $this->stored('page', 'p1')['games']);
        $this->assertNotContains('AfterSave:page:p1', $this->log);
    }

    public function test_unknown_record_ids_are_skipped(): void
    {
        $this->boot();
        $this->seedWorld();

        $this->deleter()->delete('designer', ['nope']);

        $this->assertSame([], $this->log);
        $this->assertNotNull($this->stored('designer', 'd1'));
    }

    public function test_after_save_follows_the_write(): void
    {
        $this->boot();
        $this->seedWorld();
        $stored = [];
        $this->behaviour[AfterSave::class] = function (DynamicRecord $r) use (&$stored): void {
            $stored[$r->getId()] = $this->dm->findType('page', (string) $r->getId())?->get('designers');
        };

        $this->deleter()->delete('designer', ['d1']);

        $this->assertSame('["d2"]', $stored['p2']);
    }

    public function test_ids_that_are_unknown_or_of_another_type_are_never_planned(): void
    {
        $this->boot();
        $this->seedWorld();
        // A dangling pointer to an id that has no record: deleting "ghost" must not "detach" it.
        $this->seed('page', 'p3', ['title' => 'Dangling', 'designers' => '["ghost","d2"]']);

        $plan = $this->deleter()->plan('designer', ['ghost', 'g1']); // g1 is a game, not a designer
        $this->assertSame([], array_filter($plan->deletes()));
        $this->assertSame([], $plan->detaches());
        $this->assertSame([], $this->deleter()->summary($plan)['lines']);

        $this->deleter()->delete('designer', ['ghost', 'g1']);
        $this->assertSame([], $this->log);
        $this->assertSame('["ghost","d2"]', $this->stored('page', 'p3')['designers']);
        $this->assertNotNull($this->stored('game', 'g1'));

        // Known ids among unknown ones are planned (once, however often they are posted).
        $mixed = $this->deleter()->plan('designer', ['ghost', 'd2', 'd2']);
        $this->assertSame(['d2'], $mixed->deletes()['designer'] ?? null);
    }
}
