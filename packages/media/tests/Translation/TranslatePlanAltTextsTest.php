<?php

declare(strict_types=1);

namespace Preflow\Media\Tests\Translation;

use PHPUnit\Framework\TestCase;
use Preflow\Ai\AiRequest;
use Preflow\Ai\Testing\FakeAiClient;
use Preflow\Core\Application;
use Preflow\Data\Locales;
use Preflow\I18n\Console\TranslatePlanCommand;
use Preflow\I18n\Machine\AiTranslator;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\FileJobStore;
use Preflow\I18n\Machine\FileRecordLease;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationWorker;
use Preflow\I18n\Tests\Machine\MachineTestKit;
use Preflow\Media\MediaLibrary;
use Preflow\Media\Tests\Support\MediaFixtures;
use Preflow\Media\Translation\AltTextSource;

/** translate:plan plans a media_alt job; the worker runs it: written, left alone (conflict) and nothing to do. */
final class TranslatePlanAltTextsTest extends TestCase
{
    use MachineTestKit;
    use MediaFixtures;

    private JobRunner $runner;
    private FileJobStore $store;
    private TranslationWorker $worker;
    private MediaLibrary $lib;
    private Locales $locales;

    protected function setUp(): void
    {
        $this->makeAssetRoot();
        $this->lib = $this->library();
        $this->locales = new Locales(['en', 'de'], 'en');
    }

    protected function tearDown(): void
    {
        $this->removeAssetRoot();
    }

    private function asset(array $alt): string
    {
        $asset = $this->lib->upload($this->upload($this->png(10, 10), 'a.png', 'image/png'), null);

        return $this->lib->update($asset->id, ['alt' => $alt])->id;
    }

    /** @return array{0: int, 1: string} */
    private function plan(array $args, ?\Closure $onTranslate = null): array
    {
        $dir = $this->assetRoot;
        $this->store = new FileJobStore($dir . '/ai/jobs');
        $fake = new FakeAiClient([static function (AiRequest $r) use ($onTranslate) {
            if (str_contains($r->prompt, 'A blue door')) {
                $onTranslate?->__invoke(); // a person saves while this asset is being translated
            }

            return self::echo()($r);
        }]);
        $translator = new AiTranslator($this->ai($fake), $this->settings());
        $sources = new TextSources();
        $sources->add(AltTextSource::COLLECTION, fn () => new AltTextSource($this->lib, $this->locales));
        $this->runner = new JobRunner($sources, $translator, new Estimator($translator), $this->store, new FileRecordLease($dir . '/ai/leases'));
        $this->worker = new TranslationWorker($this->runner, $this->store, $translator, $dir . '/ai/worker.json');

        ob_start();
        $code = (new TranslatePlanCommand(function () use ($sources): Application {
            $app = Application::create(['app' => ['locale' => 'en']]);
            $app->container()->instance(TextSources::class, $sources);
            $app->container()->instance(JobRunner::class, $this->runner);
            $app->container()->instance(Locales::class, $this->locales);

            return $app;
        }))->execute($args);

        return [$code, (string) ob_get_clean()];
    }

    public function test_plan_then_work_writes_leaves_changed_alone_and_skips_translated(): void
    {
        $written = $this->asset(['en' => 'A red door']);
        $raced = $this->asset(['en' => 'A blue door']);
        $skipped = $this->asset(['en' => 'Done already']); // translated before the worker runs: nothing left to do

        [$code, $out] = $this->plan(
            ['media_alt', '--locales=de', '--start', '--confirm-live'],
            fn () => $this->lib->update($raced, ['alt' => ['de' => 'Von Hand']]),
        );
        $this->assertSame(0, $code);
        $this->assertStringContainsString('(queued)', $out);
        $this->assertCount(3, $this->store->recent(10)[0]->items);
        $this->lib->update($skipped, ['alt' => ['de' => 'Schon da']]);

        $this->worker->work(20, static function (string $line): void {});

        $this->assertSame('DE:A red door', $this->lib->find($written)->alt['de']);
        $this->assertSame('Von Hand', $this->lib->find($raced)->alt['de']);
        $this->assertSame('Schon da', $this->lib->find($skipped)->alt['de']);
        $items = $this->store->recent(10)[0]->items;
        $this->assertSame('done', $items[$written]['status']);
        $this->assertSame('conflict', $items[$raced]['status']);
        $this->assertSame('skipped', $items[$skipped]['status']);
    }

    public function test_an_unknown_collection_or_language_is_refused(): void
    {
        [$code] = $this->plan(['nope', '--locales=de']);
        $this->assertSame(1, $code);
        [$code] = $this->plan(['media_alt', '--locales=fr']);
        $this->assertSame(1, $code);
        [$code] = $this->plan(['media_alt']);
        $this->assertSame(1, $code);
        $this->assertSame([], $this->store->recent(10));
    }

    public function test_starting_a_job_that_writes_live_needs_confirm_live(): void
    {
        $this->asset(['en' => 'A red door']);

        [$code, $out] = $this->plan(['media_alt', '--locales=de', '--start']);

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame([], $this->store->recent(10), 'no job was planned');
    }

    public function test_confirm_live_without_start_is_refused(): void
    {
        $this->asset(['en' => 'A red door']);

        [$code, $out] = $this->plan(['media_alt', '--locales=de', '--confirm-live']);

        $this->assertSame(1, $code);
        $this->assertSame('', $out);
        $this->assertSame([], $this->store->recent(10));
    }

    public function test_planning_without_start_needs_no_confirmation(): void
    {
        $this->asset(['en' => 'A red door']);

        [$code, $out] = $this->plan(['media_alt', '--locales=de']);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('(planned)', $out);
    }
}
