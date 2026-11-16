<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\SqliteDriver;
use Preflow\Data\Migration\Migrator;
use Preflow\Data\Model;
use Preflow\Data\Schema\ModelExactness;

#[Entity(table: 'mx_codes', storage: 'sqlite')]
final class ExactnessCode extends Model
{
    #[Id] public string $uuid = '';
    #[Field(exact: true)] public string $code = '';   // the migration forgot ->exact()
    #[Field] public string $slug = '';                // the migration made it exact
    #[Field(exact: true)] public string $key = '';    // both agree
    #[Field] public string $title = '';               // both agree
    #[Field] public int $count = 0;                   // no text
}

/** M9: #[Field(exact)] and the migration's ->exact() must agree; migrate lists each field where they don't. */
final class ModelExactnessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/model_exactness_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/migrations', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_each_disagreeing_field_is_listed(): void
    {
        file_put_contents($this->dir . '/migrations/2026_11_16_000001_mx_codes.php', <<<'PHP'
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        use Preflow\Data\Migration\Table;
        return new class extends Migration {
            public function up(Schema $schema): void
            {
                $schema->create('mx_codes', function (Table $t) {
                    $t->uuid('uuid')->primary();
                    $t->string('code');
                    $t->string('slug')->exact();
                    $t->string('key')->exact();
                    $t->string('title');
                    $t->integer('count');
                });
            }
        };
        PHP);
        $driver = new SqliteDriver(new \PDO('sqlite:' . $this->dir . '/db.sqlite'));
        (new Migrator($driver->pdo(), $this->dir . '/migrations'))->migrate();
        $dm = new DataManager(['sqlite' => $driver], 'sqlite');

        $lines = (new ModelExactness($dm, [ExactnessCode::class], 'sqlite'))->check();

        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('warning: ' . ExactnessCode::class . '::$code is #[Field(exact: true)], but its migration made mx_codes.code a text column', $lines[0]);
        $this->assertStringStartsWith('warning: ' . ExactnessCode::class . '::$slug is a text field, but its migration made mx_codes.slug exact', $lines[1]);
        $this->assertSame([], (new ModelExactness($dm, [ExactnessCode::class], null))->check(), 'nothing where no migration ran');
    }
}
