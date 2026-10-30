<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiUnavailable;
use Preflow\Core\Application;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\MachineServiceProvider;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\I18n\Machine\TranslationUnit;

/** The translator resolves with AI off; only using it says AI is unavailable. */
final class MachineServiceProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/machine_provider_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/config', 0777, true);
        file_put_contents($this->dir . '/config/app.php', "<?php\nreturn ['name' => 'Machine', 'debug' => 1, 'engine' => 'twig', 'key' => 'machine-test-key'];\n");
        file_put_contents($this->dir . '/config/providers.php', "<?php\nreturn [\\" . MachineServiceProvider::class . "::class];\n");
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_resolves_with_ai_off_and_throws_only_on_use(): void
    {
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $translator = $c->get(MachineTranslator::class);
        $this->assertInstanceOf(AiTranslator::class, $translator);
        $this->assertSame($translator, $c->get(MachineTranslator::class));
        $this->assertInstanceOf(Estimator::class, $c->get(Estimator::class));
        $this->assertInstanceOf(TranslationSettings::class, $c->get(TranslationSettings::class));

        $this->expectException(AiUnavailable::class);
        $translator->translateUnits([new TranslationUnit(id: 'a', label: 'A', source: 'Hi', to: 'de', from: 'en')]);
    }

    public function test_batch_chars_read_from_config(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['translation' => ['batch_chars' => 50]];\n");
        $app = Application::create($this->dir);
        $app->boot();
        $units = [];
        for ($i = 0; $i < 4; $i++) {
            $units[] = new TranslationUnit(id: 'u' . $i, label: 'L', source: str_repeat('a', 40), to: 'de', from: 'en');
        }

        $this->assertSame(4, $app->container()->get(Estimator::class)->estimate($units)->requests);
    }
}
