<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\I18n\Console\TranslatePlanCommand;
use Preflow\I18n\Console\TranslateWorkCommand;
use Preflow\I18n\Machine\AiPackage;
use Preflow\I18n\Machine\Estimate;
use Preflow\I18n\Machine\Estimator;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\JobStore;
use Preflow\I18n\Machine\MachineServiceProvider;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\MissingAiTranslator;
use Preflow\I18n\Machine\ReviewIndex;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationFailed;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\TranslationWorker;
use Preflow\I18n\Tests\Machine\Support\ArrayTextSource;
use Preflow\I18n\Tests\Support\HiddenAiPackage;
use Preflow\I18n\Tests\Support\TempLang;
use Preflow\Testing\ChecksFrameBalance;

/**
 * preflow/ai is only suggested: without it the machine-translation services still resolve (Folio
 * and the commands depend on them), the translator is never available, and every way of running a
 * translation says "machine translation needs preflow/ai" instead of failing on a missing class.
 * The no-ai cases run in their own process with the ai package's classes hidden (HiddenAiPackage).
 */
final class I18nWithoutAiTest extends TestCase
{
    use ChecksFrameBalance;

    use TempLang;

    protected function setUp(): void
    {
        $this->file('config/app.php', "<?php\nreturn ['name' => 'Machine', 'debug' => 1, 'engine' => 'twig', 'key' => 'machine-test-key'];\n");
        $this->file('config/providers.php', "<?php\nreturn [\\" . MachineServiceProvider::class . "::class];\n");
        // AI switched on with a key: only the missing package keeps it off.
        $this->file('config/ai.php', "<?php\nreturn ['enabled' => true, 'provider' => 'claude', 'keys' => ['claude' => 'I18N_NO_AI_TEST_KEY']];\n");
        putenv('I18N_NO_AI_TEST_KEY=test-key');
    }

    protected function tearDown(): void
    {
        putenv('I18N_NO_AI_TEST_KEY');
        $this->removeTmp();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_the_services_resolve_and_say_what_is_missing(): void
    {
        HiddenAiPackage::hide();
        $this->assertFalse(class_exists(\Preflow\Ai\Ai::class), 'the ai package is hidden');

        $app = Application::create($this->tmp());
        $app->boot();
        $c = $app->container();

        $this->assertFalse(AiPackage::installed());
        $translator = $c->get(MachineTranslator::class);
        $this->assertInstanceOf(MissingAiTranslator::class, $translator);
        $this->assertFalse($translator->available());
        $this->assertNull($translator->estimateCost(new Estimate(1, 10, 0, 0, null, 1)));
        $this->assertInstanceOf(Estimator::class, $c->get(Estimator::class));
        $this->assertInstanceOf(JobStore::class, $c->get(JobStore::class));
        $this->assertInstanceOf(ReviewIndex::class, $c->get(ReviewIndex::class));
        $this->assertFalse($c->bound(TranslationSettings::class), 'the tuning and glossary are preflow/ai settings');

        try {
            $translator->translateUnits([new TranslationUnit(id: 'a', label: 'A', source: 'Hi', to: 'de', from: 'en')]);
            $this->fail('translating without preflow/ai must refuse');
        } catch (TranslationFailed $e) {
            $this->assertSame(MissingAiTranslator::MESSAGE, $e->getMessage());
        }

        // A job queued before the package was removed stops with the reason instead of failing its items.
        $c->get(TextSources::class)->add('games', static fn (): ArrayTextSource => new ArrayTextSource(['1' => ['title' => 'One']]));
        $runner = $c->get(JobRunner::class);
        $job = $runner->start($runner->plan('games', ['de'], Scope::Needed)->id);
        $job = $runner->step($job->id);
        $this->assertSame('stopped', $job->state);
        $this->assertSame(MissingAiTranslator::MESSAGE, $job->message);
        $this->assertSame(['1'], $job->pending(), 'nothing was translated or failed');

        $this->assertSame(0, $c->get(TranslationWorker::class)->work(1)->records);
    }

    public function test_with_the_ai_package_nothing_changes(): void
    {
        $app = Application::create($this->tmp());
        $app->boot();

        $this->assertTrue(AiPackage::installed());
        $this->assertInstanceOf(\Preflow\I18n\Machine\AiTranslator::class, $app->container()->get(MachineTranslator::class));
        $this->assertTrue($app->container()->bound(TranslationSettings::class));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_a_configured_translator_does_the_work(): void
    {
        HiddenAiPackage::hide();
        // A translator of the app's own (a translation API): only the default AiTranslator needs preflow/ai.
        $this->file('config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\" . PrefixTranslator::class . "::class]];\n");

        $app = Application::create($this->tmp());
        $app->boot();
        $c = $app->container();

        $this->assertFalse(AiPackage::installed());
        $translator = $c->get(MachineTranslator::class);
        $this->assertInstanceOf(PrefixTranslator::class, $translator);
        $this->assertTrue($translator->available());

        $source = new ArrayTextSource(['1' => ['title' => 'One'], '2' => ['title' => 'Two']]);
        $c->get(TextSources::class)->add('games', static fn (): ArrayTextSource => $source);
        $runner = $c->get(JobRunner::class);
        $job = $runner->start($runner->plan('games', ['de'], Scope::Needed)->id);
        $job = $runner->step($job->id, 5);
        $this->assertSame('done', $job->state);
        $this->assertSame(['1' => ['de' => ['title' => 'DE:One']], '2' => ['de' => ['title' => 'DE:Two']]], $source->translations);

        // The cron worker runs a queued job through it too.
        $runner->start($runner->plan('games', ['fr'], Scope::Needed)->id);
        $this->assertSame(2, $c->get(TranslationWorker::class)->work(5)->records);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_without_the_ai_package_a_misconfigured_translator_still_stops_the_boot(): void
    {
        HiddenAiPackage::hide();
        $this->file('config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\stdClass::class]];\n");
        $app = Application::create($this->tmp());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('translation.translator');
        $app->boot();
    }

    public function test_translate_work_stays_quiet_and_translate_plan_refuses_without_the_ai_package(): void
    {
        $factory = function (): Application {
            $app = Application::create($this->tmp());
            $app->boot();
            $app->container()->instance(MachineTranslator::class, new MissingAiTranslator());
            $app->container()->get(TextSources::class)->add('games', static fn (): ArrayTextSource => new ArrayTextSource(['1' => ['title' => 'One']]));

            return $app;
        };

        ob_start();
        try {
            $work = (new TranslateWorkCommand($factory))->execute([]);
            $plan = (new TranslatePlanCommand($factory))->execute(['games', '--locales=de', '--start']);
        } finally {
            $out = (string) ob_get_clean();
        }

        // cron mails whatever translate:work prints: with nothing it can run it stays silent, as with AI off.
        $this->assertSame(0, $work);
        // translate:plan is run by a person: it says why (on STDERR) and plans nothing.
        $this->assertSame(1, $plan);
        $this->assertSame('', $out);
        $this->assertSame([], $factory()->container()->get(JobRunner::class)->active());
    }
}

/** A translator of an app's own that needs no AI: "DE:" + the text. */
final class PrefixTranslator implements MachineTranslator
{
    public function available(): bool
    {
        return true;
    }

    public function estimateCost(Estimate $size): ?float
    {
        return null;
    }

    public function translate(string $text, string $to, ?\Preflow\Ai\Tuning $tuning = null, ?string $from = null, \Preflow\I18n\Machine\TextFormat $format = \Preflow\I18n\Machine\TextFormat::Plain): string
    {
        return strtoupper($to) . ':' . $text;
    }

    public function translateUnits(array $units, ?string $note = null): array
    {
        return array_map(
            fn (TranslationUnit $u): \Preflow\I18n\Machine\TranslationResult => new \Preflow\I18n\Machine\TranslationResult($u->id, \Preflow\I18n\Machine\UnitStatus::Translated, $this->translate($u->source, $u->to)),
            $units,
        );
    }
}
