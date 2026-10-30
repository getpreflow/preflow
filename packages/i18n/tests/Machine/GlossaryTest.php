<?php

declare(strict_types=1);

namespace Preflow\I18n\Tests\Machine;

use PHPUnit\Framework\TestCase;
use Preflow\I18n\Machine\Glossary;
use Preflow\I18n\Machine\GlossaryEntry;
use Preflow\I18n\Machine\TranslationUnit;

/** The glossary picks the terms a batch needs and checks the result honours them. */
final class GlossaryTest extends TestCase
{
    private function unit(string $source, ?string $type = null, string $to = 'de'): TranslationUnit
    {
        return new TranslationUnit(id: 'a', label: 'A', source: $source, to: $to, from: 'en', type: $type);
    }

    public function test_relevant_whole_word_case_insensitive(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Worker Placement', ['de' => 'Arbeitereinsatz'])]);
        $this->assertCount(1, $glossary->relevant([$this->unit('A fun worker placement game')], 'de'));
        $this->assertSame([], $glossary->relevant([$this->unit('Placement of workers')], 'de'));

        $worker = new Glossary([new GlossaryEntry('Worker', ['de' => 'Arbeiter'])]);
        $this->assertSame([], $worker->relevant([$this->unit('Many Workers')], 'de'));
        $this->assertCount(1, $worker->relevant([$this->unit('Each worker, then (worker)')], 'de'));
    }

    public function test_umlaut_term_matches_whole_word_only(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Würfel', ['en' => 'dice'])]);
        $this->assertCount(1, $glossary->relevant([$this->unit('Roll the Würfel!', to: 'en')], 'en'));
        $this->assertSame([], $glossary->relevant([$this->unit('Würfelspiel', to: 'en')], 'en'));
        $this->assertSame([], $glossary->relevant([$this->unit('Schwürfel', to: 'en')], 'en'));
    }

    public function test_type_limited_entry_only_for_that_type(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Meeple', ['de' => 'Meeple'], ['Game'])]);
        $this->assertCount(1, $glossary->relevant([$this->unit('A meeple', 'Game')], 'de'));
        $this->assertSame([], $glossary->relevant([$this->unit('A meeple', 'Article')], 'de'));
        $this->assertSame([], $glossary->relevant([$this->unit('A meeple')], 'de'));
    }

    public function test_entry_without_rule_for_locale_is_skipped(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Meeple', ['fr' => 'meeple'])]);
        $this->assertSame([], $glossary->relevant([$this->unit('A meeple')], 'de'));
    }

    public function test_keep_rule_requires_term_itself(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Deck Building', ['de' => null])]);
        $unit = $this->unit('A deck building game');
        $this->assertSame([], $glossary->mismatches($unit, 'Ein Deck Building Spiel'));
        $this->assertSame(['Deck Building'], $glossary->mismatches($unit, 'Ein Kartenaufbau-Spiel'));
    }

    public function test_mismatch_lists_missing_rendering(): void
    {
        $glossary = new Glossary([
            new GlossaryEntry('Worker Placement', ['de' => 'Arbeitereinsatz']),
            new GlossaryEntry('Meeple', ['de' => 'Meeple']),
        ]);
        $unit = $this->unit('Worker placement with a meeple');
        $this->assertSame(['Worker Placement'], $glossary->mismatches($unit, 'Worker-Placement mit einem MEEPLE'));
        $this->assertSame([], $glossary->mismatches($unit, 'arbeitereinsatz mit Meeple'));
    }

    public function test_roundtrip_array(): void
    {
        $glossary = new Glossary([
            new GlossaryEntry('Meeple', ['de' => null, 'fr' => 'figurine'], ['Game'], 'keep in German'),
            new GlossaryEntry('Dice', ['de' => 'Würfel']),
        ]);
        $rows = $glossary->toArray();
        $this->assertSame($rows, Glossary::fromArray($rows)->toArray());
        $this->assertSame('Meeple', $rows[0]['term']);
        $this->assertNull($rows[0]['translations']['de']);
    }

    public function test_empty_term_entries_are_skipped(): void
    {
        $glossary = new Glossary([new GlossaryEntry('  ', ['de' => 'x']), new GlossaryEntry('', ['de' => 'y'])]);
        $this->assertSame([], $glossary->relevant([$this->unit('Any text')], 'de'));
        $this->assertSame([], $glossary->mismatches($this->unit('Any text'), 'Ein Text'));
        $this->assertSame([], $glossary->toArray());

        $fromRows = Glossary::fromArray([['term' => ' ', 'translations' => ['de' => 'x']], ['translations' => ['de' => 'z']]]);
        $this->assertSame([], $fromRows->toArray());
    }

    public function test_whitespace_inside_term_matches_any_whitespace_run(): void
    {
        $glossary = new Glossary([new GlossaryEntry('Worker Placement', ['de' => 'Arbeitereinsatz'])]);
        $this->assertCount(1, $glossary->relevant([$this->unit('A worker  placement game')], 'de'));
        $this->assertCount(1, $glossary->relevant([$this->unit("A worker\nplacement game")], 'de'));
        $this->assertSame([], $glossary->relevant([$this->unit('A workerplacement game')], 'de'));
    }
}
