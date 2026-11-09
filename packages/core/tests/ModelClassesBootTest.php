<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\TypeRegistry;

/** A model class marked #[Entity(type: …)] is a type, even for an app with no config/models folder. */
final class ModelClassesBootTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/model_classes_boot_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/Models', 0777, true);
        mkdir($this->dir . '/app/pages', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => '" . $this->dir . "/storage']], 'default' => 'json'];\n");
        file_put_contents($this->dir . '/app/Models/BootJob.php', <<<'PHP'
<?php

namespace ModelClassesBoot\Models;

use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Model;

#[Entity(table: 'boot_job', storage: 'json', type: 'boot_job')]
final class BootJob extends Model
{
    #[Field]
    public string $title = '';
}
PHP);
        file_put_contents($this->dir . '/app/pages/index.twig', '<html><head></head><body></body></html>');
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            foreach (glob($path . '/{,.}*', GLOB_BRACE) ?: [] as $entry) {
                if (in_array(basename($entry), ['.', '..'], true)) {
                    continue;
                }
                is_dir($entry) ? $remove($entry) : unlink($entry);
            }
            rmdir($path);
        };
        $remove($this->dir);
    }

    #[RunInSeparateProcess]
    public function test_a_class_is_a_type_without_a_models_folder(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir . '/config/models');
        require_once $this->dir . '/app/Models/BootJob.php'; // the test app has no PSR-4 mapping for App

        $app = Application::create($this->dir);
        $app->boot();
        $registry = $app->container()->get(TypeRegistry::class);

        $this->assertTrue($registry->has('boot_job'));
        $this->assertSame('boot_job', $registry->get('boot_job')->table);
    }
}
