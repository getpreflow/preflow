<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Model;

#[Entity(table: 'switches', storage: 'sqlite')]
final class SwitchModel extends Model
{
    #[Id]
    public string $uuid = '';

    #[Field]
    public bool $active = true;

    #[Field]
    public ?bool $checked = null;
}

/** A bool property reads what SQL stores for it (1/0, '1'/'0'): SQLite and MySQL have no boolean. */
final class ModelBoolFieldTest extends TestCase
{
    public function test_fill_reads_stored_booleans(): void
    {
        $model = new SwitchModel();
        $model->fill(['active' => 0, 'checked' => '1']);
        $this->assertFalse($model->active);
        $this->assertTrue($model->checked);

        $model->fill(['active' => '1', 'checked' => null]);
        $this->assertTrue($model->active);
        $this->assertNull($model->checked, 'null stays null for a nullable bool');

        $model->fill(['active' => false, 'checked' => '0']);
        $this->assertFalse($model->active);
        $this->assertFalse($model->checked);
    }

    public function test_a_bool_property_round_trips_through_sqlite(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE switches (uuid TEXT PRIMARY KEY, active INTEGER, checked INTEGER)');
        $dm = new DataManager(['sqlite' => new SqliteDriver($pdo)]);
        $model = new SwitchModel();
        $model->uuid = 's1';
        $model->active = false;
        $dm->save($model);

        $found = $dm->find(SwitchModel::class, 's1');
        $this->assertInstanceOf(SwitchModel::class, $found);
        $this->assertFalse($found->active);
        $this->assertNull($found->checked);
        $this->assertTrue($dm->query(SwitchModel::class)->where('uuid', 's1')->first() instanceof SwitchModel);
    }
}
