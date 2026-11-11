<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Ai\Tuning;
use Preflow\Core\Application;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Console\TranslateWorkCommand;
use Preflow\I18n\Machine\Estimate;
use Preflow\I18n\Machine\JobRunner;
use Preflow\I18n\Machine\MachineTranslator;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TranslationResult;
use Preflow\I18n\Machine\UnitStatus;
use Psr\Http\Message\ResponseInterface;

/**
 * A translator that is no AI (config/ai.php translation.translator, 0.66), with AI off: Folio's
 * Translate, the estimate and translate:work all use it; nothing asks for an AI key.
 */
final class NonAiTranslatorTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        // AI is off (no "enabled", no key); only the translator is configured.
        $this->write('config/ai.php', "<?php\nreturn ['translation' => ['translator' => \\" . DictionaryTranslator::class . "::class]];\n");
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello'], '_version' => 1]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function booted(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    /** @param array<string, mixed> $body */
    private function send(string $uri, array $body): ResponseInterface
    {
        return $this->booted()->handle((new Psr17Factory())->createServerRequest('POST', $uri)->withHeader('Sec-Fetch-Site', 'same-origin')->withParsedBody($body));
    }

    #[RunInSeparateProcess]
    public function test_translate_and_the_estimate_use_the_translator_with_ai_off(): void
    {
        $this->assertFalse($this->booted()->container()->get(\Preflow\Ai\Ai::class)->enabled(), 'AI stays off');

        $res = $this->send('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed']);
        $this->assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $data = json_decode((string) $res->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Hallo', $data['texts']['title']['text']);

        $estimate = json_decode((string) $this->send('/folio/post/p1/_ai/estimate', ['locale' => 'de', 'scope' => 'needed'])->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $estimate['units']);
        $this->assertEqualsWithDelta(10 * 0.001, $estimate['costUsd'], 1e-9, 'priced by the translator: Hello + hello, 10 characters');
    }

    #[RunInSeparateProcess]
    public function test_translate_work_runs_a_queued_job_with_the_translator(): void
    {
        $runner = $this->booted()->container()->get(JobRunner::class);
        $job = $runner->plan('post', ['de'], Scope::Needed);
        $runner->start($job->id);

        ob_start();
        $code = (new TranslateWorkCommand(fn (): Application => $this->booted()))->execute(['--seconds=20']);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('1 records', $out);
        $this->assertSame('Hallo', $this->stored('post', 'p1')->get('title')['de'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_an_unavailable_translator_hides_translate(): void
    {
        DictionaryTranslator::$available = false;
        try {
            $this->assertSame(404, $this->send('/folio/post/p1/_ai/suggest', ['locale' => 'de', 'scope' => 'needed'])->getStatusCode());
        } finally {
            DictionaryTranslator::$available = true;
        }
    }
}

/** A translation API stand-in: a fixed dictionary, priced per character, needs no AI. */
final class DictionaryTranslator implements MachineTranslator
{
    public static bool $available = true;

    private const WORDS = ['Hello' => 'Hallo', 'hello' => 'hallo'];

    public function available(): bool
    {
        return self::$available;
    }

    public function estimateCost(Estimate $size): ?float
    {
        return $size->characters * 0.001;
    }

    public function translate(string $text, string $to, ?Tuning $tuning = null, ?string $from = null, TextFormat $format = TextFormat::Plain): string
    {
        return self::WORDS[$text] ?? $text;
    }

    public function translateUnits(array $units, ?string $note = null): array
    {
        return array_map(static fn ($u): TranslationResult => new TranslationResult($u->id, UnitStatus::Translated, self::WORDS[$u->source] ?? $u->source), $units);
    }
}
