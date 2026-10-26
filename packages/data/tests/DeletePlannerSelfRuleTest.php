<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DeletedBy;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\OnDelete;
use Preflow\Data\TypeRegistry;

final class DeletePlannerSelfRuleTest extends TestCase
{
    private string $dir;
    private TypeRegistry $registry;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dpself_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/models/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir . '/models');
        rmdir($this->dir);
    }

    /** @param array<string, mixed> $relation */
    private function dm(array $relation): DataManager
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $types = [
            'person' => ['name' => ['type' => 'string']],
            'item' => ['owner' => ['type' => 'relation', 'relation' => ['to' => 'person'] + $relation]],
        ];
        foreach ($types as $key => $fields) {
            file_put_contents($this->dir . "/models/{$key}.json", json_encode([
                'key' => $key, 'table' => $key, 'storage' => 'sqlite', 'id_field' => 'uuid', 'fields' => $fields,
            ]));
            $cols = implode('', array_map(static fn (string $c) => ", {$c} TEXT", array_keys($fields)));
            $pdo->exec("CREATE TABLE {$key} (uuid TEXT PRIMARY KEY{$cols})");
        }
        $this->registry = new TypeRegistry($this->dir . '/models', new Locales(['en'], 'en'));
        $dm = new DataManager(drivers: ['sqlite' => new SqliteDriver($pdo)], defaultDriver: 'sqlite', typeRegistry: $this->registry);
        $dm->saveType(DynamicRecord::fromArray($this->registry->get('person'), ['uuid' => 'p1', 'name' => 'P']), validate: false);
        $dm->saveType(DynamicRecord::fromArray($this->registry->get('item'), ['uuid' => 'i1', 'owner' => 'p1']), validate: false);

        return $dm;
    }

    public function test_staff_is_refused_but_self_detaches(): void
    {
        $dm = $this->dm(['onDelete' => 'restrict', 'onSelfDelete' => 'detach']);

        $this->assertTrue($dm->deletePlan('person', ['p1'], null, DeletedBy::Staff)->restricted());

        $self = $dm->deletePlan('person', ['p1'], null, DeletedBy::Self);
        $this->assertFalse($self->restricted());
        $this->assertSame([['type' => 'item', 'id' => 'i1', 'field' => 'owner', 'remove' => ['p1']]], $self->detaches());
    }

    public function test_default_is_staff(): void
    {
        $dm = $this->dm(['onDelete' => 'restrict', 'onSelfDelete' => 'detach']);

        $this->assertTrue($dm->deletePlan('person', ['p1'])->restricted());
    }

    public function test_without_a_self_rule_both_cascade(): void
    {
        $dm = $this->dm(['onDelete' => 'cascade']);

        foreach ([DeletedBy::Staff, DeletedBy::Self] as $by) {
            $this->assertSame(['person' => ['p1'], 'item' => ['i1']], $dm->deletePlan('person', ['p1'], null, $by)->deletes());
        }
    }

    public function test_self_restrict_refuses_a_self_plan(): void
    {
        $dm = $this->dm(['onDelete' => 'detach', 'onSelfDelete' => 'restrict']);

        $this->assertFalse($dm->deletePlan('person', ['p1'], null, DeletedBy::Staff)->restricted());
        $this->assertTrue($dm->deletePlan('person', ['p1'], null, DeletedBy::Self)->restricted());
    }

    public function test_self_cascade_only_cascades_for_self(): void
    {
        $dm = $this->dm(['onDelete' => 'detach', 'onSelfDelete' => 'cascade']);

        $this->assertSame(['person' => ['p1']], $dm->deletePlan('person', ['p1'])->deletes());
        $this->assertSame(['person' => ['p1'], 'item' => ['i1']], $dm->deletePlan('person', ['p1'], null, DeletedBy::Self)->deletes());
    }

    public function test_invalid_self_rule_falls_back_to_on_delete(): void
    {
        $dm = $this->dm(['onDelete' => 'restrict', 'onSelfDelete' => 'bogus']);

        $this->assertTrue($dm->deletePlan('person', ['p1'], null, DeletedBy::Self)->restricted());
        $relation = $dm->relationsTo('person')[0];
        $this->assertSame(OnDelete::Restrict, $relation->onSelfDelete);
    }
}
