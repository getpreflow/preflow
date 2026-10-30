<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Console;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\Ai;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Core\Application;
use Preflow\I18n\Console\TranslateWorkCommand;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\FileRecordLease;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationWorker;
use Preflow\I18n\Tests\Machine\MachineTestKit;
use Preflow\I18n\Tests\Machine\Support\ArrayTextSource;
use Preflow\I18n\Tests\Support\TempLang;

final class TranslateWorkCommandTest extends TestCase
{
    use MachineTestKit;
    use TempLang;

    protected function setUp(): void
    {
        $this->file('config/app.php', "<?php\nreturn ['name'=>'t','debug'=>0,'engine'=>'twig','key'=>'k','locale'=>'en'];\n");
    }

    protected function tearDown(): void
    {
        $this->removeTmp();
    }

    /** @return array{0: int, 1: string, 2: FileJobStore} */
    private function work(bool $enabled, array $args = []): array
    {
        $dir = $this->tmp();
        $store = new FileJobStore($dir . '/ai/jobs');
        $ai = $this->ai(new FakeAiClient([self::echo()]), [], $enabled);
        $sources = new TextSources();
        $sources->add('games', fn () => new ArrayTextSource(['1' => ['title' => 'One'], '2' => ['title' => 'Two']]));
        $runner = new JobRunner($sources, new AiTranslator($ai, $this->settings()), new Estimator($ai), $store, new FileRecordLease($dir . '/ai/leases'));
        $job = $runner->plan('games', ['de'], Scope::Needed);
        $runner->start($job->id);

        ob_start();
        $code = (new TranslateWorkCommand(static function () use ($dir, $ai, $store, $runner): Application {
            $app = Application::create($dir);
            $app->boot();
            $app->container()->instance(Ai::class, $ai);
            $app->container()->instance(TranslationWorker::class, new TranslationWorker($runner, $store, $ai, $dir . '/ai/worker.json'));

            return $app;
        }))->execute($args);

        return [$code, (string) ob_get_clean(), $store];
    }

    public function test_prints_records_and_cost(): void
    {
        [$code, $out] = $this->work(true, ['--seconds=20']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("games 1 de: done\n", $out);
        $this->assertStringContainsString("games 2 de: done\n", $out);
        $this->assertMatchesRegularExpression('/^2 records, \$\d+\.\d{4}/m', $out);
    }

    public function test_ai_off_exits_zero_silently(): void
    {
        [$code, $out, $store] = $this->work(false);

        $this->assertSame(0, $code);
        $this->assertSame('', $out);
        $this->assertSame('queued', $store->recent(1)[0]->state);
        $this->assertFileExists($this->tmp() . '/ai/worker.json', 'the heartbeat is written even with AI off');
    }
}
