<?php

declare(strict_types=1);

namespace Preflow\Core\Tests\PageCache;

use PHPUnit\Framework\TestCase;
use Preflow\Core\PageCache\ChangeLog;
use Preflow\Core\PageCache\DataLayerObserver;
use Preflow\Core\PageCache\DependencyRecorder;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/** Reads and writes of a class-backed type depend on and change what the same JSON type's do. */
final class ClassTypeTagsTest extends TestCase
{
    private DataManager $dm;

    private DependencyRecorder $recorder;

    private ChangeLog $changes;

    private TypeRegistry $types;

    private string $dir;

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map preflow/data's test namespace.
        require_once dirname(__DIR__, 3) . '/data/tests/Fixtures/ModelSchema/Job.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/class_type_tags_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach (['job', 'job__published'] as $table) {
            $pdo->exec("CREATE TABLE {$table} (uuid TEXT PRIMARY KEY, title TEXT, remote TEXT, company TEXT, logo TEXT, salary_from INTEGER, open INTEGER)");
            $pdo->exec("INSERT INTO {$table} (uuid, title) VALUES ('a1', 'Carpenter')");
        }
        $this->types = new TypeRegistry($this->dir, null, ['job' => Job::class]);
        $this->dm = new DataManager(['default' => new SqliteDriver($pdo)], 'default', $this->types);
        $this->recorder = new DependencyRecorder();
        $this->changes = new ChangeLog(static function (array $tags): void {}, flushAtShutdown: false);
        $this->dm->observe(new DataLayerObserver($this->recorder, $this->changes));
    }

    protected function tearDown(): void
    {
        rmdir($this->dir);
    }

    /** @return list<string> the dependencies $fn records */
    private function reads(\Closure $fn): array
    {
        $this->recorder->start();
        $fn();

        return array_keys($this->recorder->stop());
    }

    /** @return list<string> the changes $fn reports */
    private function writes(\Closure $fn): array
    {
        $this->changes->flush();
        $fn();
        $changed = $this->changes->pending();
        $this->changes->flush();

        return $changed;
    }

    public function test_reads_record_the_types_tags(): void
    {
        $published = $this->types->get('job');
        $shadow = new TypeDefinition($published->key, 'job__published', $published->storage, $published->fields, $published->idField);

        $this->assertContains('default:job#a1', $this->reads(fn () => $this->dm->find(Job::class, 'a1')));
        $this->assertSame($this->reads(fn () => $this->dm->findType('job', 'a1')), $this->reads(fn () => $this->dm->find(Job::class, 'a1')));
        $this->assertSame($this->reads(fn () => $this->dm->queryType('job')->get()), $this->reads(fn () => $this->dm->query(Job::class)->get()));
        $this->assertContains('default:job__published#a1', $this->reads(fn () => $this->dm->findAs(Job::class, 'a1', 'job__published')));
        $this->assertSame($this->reads(fn () => $this->dm->findIn($shadow, 'a1')), $this->reads(fn () => $this->dm->findAs(Job::class, 'a1', 'job__published')));
        $this->assertSame($this->reads(fn () => $this->dm->queryIn($shadow)->get()), $this->reads(fn () => $this->dm->queryAs(Job::class, 'job__published')->get()));
    }

    public function test_writes_change_the_types_tags(): void
    {
        $job = $this->dm->find(Job::class, 'a1');
        $job->title = 'Joiner';
        $typed = $this->writes(fn () => $this->dm->save($job));
        $record = $this->dm->findType('job', 'a1');
        $dynamic = $this->writes(fn () => $this->dm->saveType($record, validate: false));

        $this->assertContains('default:job#a1', $typed);
        $this->assertSame($dynamic, $typed);
        $this->assertSame(
            $this->writes(fn () => $this->dm->deleteType('job', 'a1')),
            $this->writes(fn () => $this->dm->delete($job)),
        );

        $new = new Job();
        $new->title = 'Mason';
        $inserted = $this->writes(fn () => $this->dm->save($new));
        $this->assertContains('default:job#' . $new->uuid, $inserted, 'a new record is reported with the id it got');
        $this->assertSame($this->writes(fn () => $this->dm->saveType(DynamicRecord::fromArray($this->types->get('job'), ['uuid' => $new->uuid, 'title' => 'Mason']), validate: false)), $inserted);
    }
}
