<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Tests\Fixtures\Field\CountingFactsFieldType;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Completeness;
use Preflow\I18n\Machine\MachineMarks;

/** TranslationStatus::state(): a language's completeness and its counts, from one walk of the texts. */
final class TranslationStatusStateTest extends TestCase
{
    use PublishingScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_a_missing_block_text_makes_the_language_partial(): void
    {
        $record = $this->record([
            'title' => ['en' => 'Hello', 'de' => 'Hallo'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Welcome']]],
            '_locales' => ['en', 'de'],
        ]);

        $state = $this->translations()->state($this->def(), $record, 'de');

        $this->assertSame(Completeness::Partial, $state->completeness);
        $this->assertSame(2, $state->total, 'the title and the block headline');
        $this->assertSame(1, $state->missing);
    }

    public function test_a_language_the_record_is_not_visible_in_is_missing(): void
    {
        $record = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo']]);

        $state = $this->translations()->state($this->def(), $record, 'de');

        $this->assertSame(Completeness::Missing, $state->completeness);
        $this->assertSame([1, 0], [$state->total, $state->missing], 'the counts are still told');
    }

    public function test_every_text_missing_is_missing_and_none_is_complete(): void
    {
        $missing = $this->translations()->state($this->def(), $this->record(['title' => ['en' => 'Hello'], 'body' => ['en' => 'Text'], '_locales' => ['en', 'de']]), 'de');
        $done = $this->translations()->state($this->def(), $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], '_locales' => ['en', 'de']]), 'de');

        $this->assertSame([Completeness::Missing, 2, 2], [$missing->completeness, $missing->total, $missing->missing]);
        $this->assertSame([Completeness::Complete, 1, 0], [$done->completeness, $done->total, $done->missing]);
    }

    public function test_the_default_language_is_complete(): void
    {
        $state = $this->translations()->state($this->def(), $this->record(['title' => ['en' => 'Hello'], 'body' => ['en' => 'Text']]), 'en');

        $this->assertSame(Completeness::Complete, $state->completeness);
        $this->assertSame([2, 0, 0, 0], [$state->total, $state->missing, $state->outdated, $state->unchecked]);
        $this->assertFalse($state->stale());
    }

    public function test_outdated_and_unchecked_are_counted(): void
    {
        $status = $this->translations();
        $record = $this->record([
            'title' => ['en' => 'Hello again', 'de' => 'Hallo'],
            'body' => ['en' => 'Text', 'de' => 'Text (Maschine)'],
            '_locales' => ['en', 'de'],
            TranslationStatus::KEY => ['de' => ['title' => $status->fingerprint('Hello')]],
            TranslationStatus::MACHINE => MachineMarks::mark([], 'de', 'body', 'Text (Maschine)'),
        ]);

        $state = $status->state($this->def(), $record, 'de');

        $this->assertSame(Completeness::Complete, $state->completeness, 'nothing missing');
        $this->assertSame([1, 1], [$state->outdated, $state->unchecked]);
        $this->assertTrue($state->stale());
        $this->assertSame(count($status->outdated($this->def(), $record, 'de')), $state->outdated);
        $this->assertSame(count($status->unchecked($this->def(), $record, 'de')), $state->unchecked);
    }

    public function test_the_texts_are_read_once(): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'field_types' => [\\" . CountingFactsFieldType::class . "::class]];\n");
        $this->writeModel('post', ['fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'facts' => ['type' => 'counted_facts'],
        ]]);
        $def = $this->dm()->getTypeRegistry()->get('post');
        $record = DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => ['en' => 'T', 'de' => 'T'], '_locales' => ['en', 'de'], 'facts' => [
            ['id' => 'f1', 'text' => ['en' => 'One', 'de' => 'Eins']],
            ['id' => 'f2', 'text' => ['en' => 'Two']],
        ], TranslationStatus::KEY => ['de' => ['facts.f1.text' => 'x']], TranslationStatus::MACHINE => MachineMarks::mark([], 'de', 'facts.f1.text', 'Eins')]);
        $status = $this->translations();
        CountingFactsFieldType::$calls = 0;

        $state = $status->state($def, $record, 'de');

        $this->assertSame(1, CountingFactsFieldType::$calls);
        $this->assertSame([Completeness::Partial, 3, 1, 1, 1], [$state->completeness, $state->total, $state->missing, $state->outdated, $state->unchecked]);
    }

    private function translations(): TranslationStatus
    {
        return $this->app()->container()->get(TranslationStatus::class);
    }

    private function def(): \Preflow\Data\TypeDefinition
    {
        return $this->dm()->getTypeRegistry()->get('post');
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->def(), ['uuid' => 'p1'] + $data);
    }
}
