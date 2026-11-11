<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiUnavailable;
use Preflow\Core\Application;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimate;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\Job;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\JobStore;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
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

    public function test_binds_sources_and_job_runner_with_jobs_under_storage(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['storage' => 'var/ai'];\n");
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $this->assertSame($c->get(TextSources::class), $c->get(TextSources::class));
        $this->assertInstanceOf(JobRunner::class, $c->get(JobRunner::class));

        $job = new Job('j1', 'games', ['de'], Scope::Needed, null, new Estimate(0, 0, 0, 0, null, 0));
        $c->get(JobStore::class)->save($job);
        $this->assertFileExists($this->dir . '/var/ai/jobs/j1.json');
    }

    public function test_the_translator_is_the_configured_class_built_by_the_container(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\" . UpperTranslator::class . "::class]];\n");
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();

        $translator = $c->get(MachineTranslator::class);
        $this->assertInstanceOf(UpperTranslator::class, $translator);
        $this->assertSame('HI', $translator->translate('Hi', 'de'));
        $this->assertSame($translator->settings, $c->get(TranslationSettings::class), 'its constructor got a service');
        $this->assertSame($translator, (new \ReflectionProperty(JobRunner::class, 'translator'))->getValue($c->get(JobRunner::class)), 'the job runner translates with it');
    }

    public function test_a_translator_class_that_is_no_machine_translator_stops_the_boot(): void
    {
        file_put_contents($this->dir . '/config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\stdClass::class]];\n");
        $app = Application::create($this->dir);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('translation.translator');
        $app->boot();
    }
}

/** A translator of an app's own (a glossary service, a translation API): config/ai.php translation.translator. */
final class UpperTranslator implements MachineTranslator
{
    public function __construct(public readonly TranslationSettings $settings) {}

    public function translate(string $text, string $to, ?\Preflow\Ai\Tuning $tuning = null, ?string $from = null, \Preflow\I18n\Machine\TextFormat $format = \Preflow\I18n\Machine\TextFormat::Plain): string
    {
        return strtoupper($text);
    }

    public function translateUnits(array $units, ?string $note = null): array
    {
        return [];
    }
}
