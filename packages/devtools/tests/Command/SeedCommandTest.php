<?php

declare(strict_types=1);

namespace Preflow\DevTools\Tests\Command;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\DevTools\Command\MigrateCommand;
use Preflow\DevTools\Command\SeedCommand;

/** db:seed against a temp SQLite site: seeders are built by the booted app's container. */
final class SeedCommandTest extends TestCase
{
    private string $dir;
    private string $tag;
    private \Closure $autoloader;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/seed_cmd_' . bin2hex(random_bytes(4));
        $this->tag = bin2hex(random_bytes(3));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/migrations', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'Seed Test','debug'=>0,'engine'=>'twig','key'=>'seed-test-key-0123456789abcdefgh'];\n");
        file_put_contents($this->dir . '/config/data.php', '<?php return ["default" => "sqlite", "drivers" => ["sqlite" => ["path" => ' . var_export($this->dir . '/app.sqlite', true) . ']]];');
        file_put_contents($this->dir . '/migrations/2026_01_01_000001_create_marks.php', <<<'PHP'
        <?php
        use Preflow\Data\Migration\Migration;
        use Preflow\Data\Migration\Schema;
        return new class extends Migration {
            public function up(Schema $schema): void
            {
                $schema->create('marks', function ($t) { $t->uuid('uuid')->primary(); $t->string('value'); });
            }
        };
        PHP);

        // Seeder classes are unique per test (a class can't be redefined), so the autoloader maps App\Seeds\* to the temp dir.
        $seeds = $this->dir . '/app/Seeds/';
        $this->autoloader = static function (string $class) use ($seeds): void {
            if (str_starts_with($class, 'App\\Seeds\\') && is_file($file = $seeds . substr($class, 10) . '.php')) {
                require_once $file;
            }
        };
        spl_autoload_register($this->autoloader);
        mkdir($seeds, 0777, true);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoloader);
        putenv('SEED_MARK');
        unset($_ENV['SEED_MARK']);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function app(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** A seeder writing $valueExpr into the marks table. */
    private function seeder(string $name, string $valueExpr): string
    {
        $class = $name . $this->tag;
        file_put_contents($this->dir . "/app/Seeds/{$class}.php", <<<PHP
        <?php
        namespace App\Seeds;
        final class {$class}
        {
            public function run(\Preflow\Data\DataManager \$data): void
            {
                \$data->driver('default')->pdo()->prepare('INSERT INTO marks (uuid, value) VALUES (?, ?)')
                    ->execute(['{$class}', {$valueExpr}]);
            }
        }
        PHP);

        return $class;
    }

    /** @return list<string> */
    private function marks(): array
    {
        return (new \PDO('sqlite:' . $this->dir . '/app.sqlite'))->query('SELECT uuid || "=" || value FROM marks ORDER BY uuid')->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** @return array{0:int,1:string} */
    private function seed(array $args = []): array
    {
        $migrate = new MigrateCommand(fn () => $this->app());
        ob_start();
        $migrate->execute([]);
        ob_end_clean();

        ob_start();
        $code = (new SeedCommand(fn () => $this->app()))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_runs_seeders_in_filename_order(): void
    {
        $this->seeder('B', "'b'");
        $this->seeder('A', "'a'");

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code, $out);
        $this->assertLessThan(strpos($out, 'B' . $this->tag), strpos($out, 'A' . $this->tag));
        $this->assertCount(2, $this->marks());
    }

    public function test_no_seeders_directory_is_not_an_error(): void
    {
        rmdir($this->dir . '/app/Seeds');

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code);
        $this->assertStringContainsString('No seeders directory found', $out);
    }

    public function test_seeder_gets_constructor_dependencies_from_the_container(): void
    {
        $class = 'Dep' . $this->tag;
        file_put_contents($this->dir . "/app/Seeds/{$class}.php", <<<PHP
        <?php
        namespace App\Seeds;
        final class {$class}
        {
            public function __construct(private readonly \Preflow\Core\Config\Config \$config) {}
            public function run(\Preflow\Data\DataManager \$data): void
            {
                \$data->driver('default')->pdo()->prepare('INSERT INTO marks (uuid, value) VALUES (?, ?)')
                    ->execute(['x', \$this->config->get('app.name')]);
            }
        }
        PHP);

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code, $out);
        $this->assertSame(['x=Seed Test'], $this->marks());
    }

    public function test_class_option_runs_only_that_seeder(): void
    {
        $this->seeder('ASeeder', "'a'");
        $this->seeder('BSeeder', "'b'");

        [$code, $out] = $this->seed(['--class=BSeeder' . $this->tag]);

        $this->assertSame(0, $code, $out);
        $this->assertSame(['BSeeder' . $this->tag . '=b'], $this->marks());

        // A fully qualified name works too.
        [$code] = $this->seed(['--class=App\\Seeds\\ASeeder' . $this->tag]);
        $this->assertSame(0, $code);
        $this->assertCount(2, $this->marks());
    }

    public function test_unknown_class_exits_1_with_message(): void
    {
        $this->seeder('ASeeder', "'a'");

        [$code, $out] = $this->seed(['--class=Nope']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Seeder Nope not found.', $out);
        $this->assertSame([], $this->marks());
    }

    public function test_env_file_is_loaded(): void
    {
        file_put_contents($this->dir . '/.env', "SEED_MARK=from-env\n");
        $this->seeder('Env', "getenv('SEED_MARK')");

        [$code, $out] = $this->seed();

        $this->assertSame(0, $code, $out);
        $this->assertSame(['Env' . $this->tag . '=from-env'], $this->marks());
    }

    public function test_failing_seeder_exits_1(): void
    {
        $class = 'Boom' . $this->tag;
        file_put_contents($this->dir . "/app/Seeds/{$class}.php", <<<PHP
        <?php
        namespace App\Seeds;
        final class {$class}
        {
            public function run(\Preflow\Data\DataManager \$data): void { throw new \RuntimeException('kaput'); }
        }
        PHP);

        [$code, $out] = $this->seed();

        $this->assertSame(1, $code);
        $this->assertStringContainsString("Seeder {$class} failed: kaput", $out);
    }
}
