<?php

declare(strict_types=1);

namespace Preflow\Core\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;

/** Labels work without Folio: the declared ones, translated when i18n is installed (0.93). */
final class LabelsWiringTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/labels_wiring_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        mkdir($this->dir . '/app/Models', 0777, true);
        mkdir($this->dir . '/lang/en', 0777, true);
        mkdir($this->dir . '/lang/de', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'a-real-secret-key'];\n");
        file_put_contents($this->dir . '/config/data.php', "<?php\nreturn ['default' => 'json', 'drivers' => ['json' => ['path' => '{$this->dir}/json']], 'models_path' => '{$this->dir}/app/Models'];\n");
        file_put_contents($this->dir . '/config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en'];\n");
        file_put_contents($this->dir . '/lang/en/site.php', "<?php\nreturn ['types' => ['job' => 'Jobs']];\n");
        file_put_contents($this->dir . '/lang/de/site.php', "<?php\nreturn ['types' => ['job' => 'Stellen']];\n");
        file_put_contents($this->dir . '/app/Models/job.json', json_encode(['key' => 'job', 'table' => 'jobs', 'storage' => 'default', 'label' => 'site.types.job', 'fields' => ['title' => ['type' => 'string']]]));
        file_put_contents($this->dir . '/app/Models/note.json', json_encode(['key' => 'note', 'table' => 'notes', 'storage' => 'default', 'label' => 'Notizen', 'fields' => ['title' => ['type' => 'string']]]));
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $rm = function (string $p) use (&$rm): void {
            foreach (glob($p . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (in_array(basename($f), ['.', '..'], true)) {
                    continue;
                }
                is_dir($f) ? $rm($f) : unlink($f);
            }
            @rmdir($p);
        };
        $rm($this->dir);
    }

    public function test_application_without_i18n_declared_labels(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $labels = $app->container()->get(DataManager::class)->labels();

        $this->assertSame('Notizen', $labels->type('note', 'de'), 'a plain label as declared');
        $this->assertSame('Unknown', $labels->type('unknown', 'en'), 'the key, capitalised');
        // With the translator booted from lang/, a key-shaped label is translated per locale.
        $this->assertSame('Stellen', $labels->type('job', 'de'));
        $this->assertSame('Jobs', $labels->type('job', 'en'));
    }
}
