<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FolioTextSource;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Publishing\Publisher;
use Preflow\Folio\Publishing\PublishState;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Tests\Publishing\RacingDriver;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TextSource;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\WriteOutcomeStatus;

final class FolioTextSourceTest extends TestCase
{
    use PublishingScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModels();
    }

    private function writeModels(): void
    {
        $this->writeModel('article', [
            'label' => 'Articles',
            'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'summary' => ['type' => 'text', 'translatable' => true, 'ai' => ['tuning' => 'Keep it short.']],
                'secret' => ['type' => 'string', 'translatable' => true, 'ai' => ['translate' => false]],
                'sections' => ['type' => 'blocks'],
            ],
        ]);
        $this->writeModel('memo', [
            'label' => 'Memos',
            'publishing' => false,
            'fields' => ['text' => ['type' => 'string', 'translatable' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_needed_units_missing_and_outdated_with_previous_source_and_current(): void
    {
        $fp = $this->translationStatus()->fingerprint('Old summary');
        $this->working('article', 'a1', [
            'title' => ['en' => 'Hello'],
            'summary' => ['en' => 'New summary', 'de' => 'Alte Zusammenfassung'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head', 'de' => 'Kopf']]],
            TranslationStatus::KEY => ['de' => ['summary' => $fp]],
            TranslationStatus::SOURCES => ['de' => ['summary' => 'Old summary']],
        ]);

        $units = $this->byId($this->source('article')->units('a1', 'de', Scope::Needed));

        $this->assertSame(['title', 'summary'], array_keys($units), 'the up-to-date block leaf is not needed');
        $title = $units['title'];
        $this->assertSame(['Title', 'Hello', 'de', 'en', null, null], [$title->label, $title->source, $title->to, $title->from, $title->current, $title->previousSource]);
        $this->assertSame(['type' => 'Articles', 'record' => 'Hello'], $title->context);
        $this->assertSame('article', $title->type);
        $this->assertNull($title->tuning);

        $summary = $units['summary'];
        $this->assertSame('New summary', $summary->source);
        $this->assertSame('Alte Zusammenfassung', $summary->current);
        $this->assertSame('Old summary', $summary->previousSource);
        $this->assertSame('Keep it short.', $summary->tuning, 'the field\'s own "ai" tuning');
        $this->assertSame(TextFormat::Plain, $summary->format);

        $all = $this->byId($this->source('article')->units('a1', 'de', Scope::All));
        $this->assertSame(['title', 'summary', 'sections.' . self::A . '.headline'], array_keys($all));
        $this->assertSame('Kopf', $all['sections.' . self::A . '.headline']->current);
        $this->assertSame('Head', $all['sections.' . self::A . '.headline']->source);

        $this->assertSame([], $this->source('article')->units('a1', 'en', Scope::Needed), 'the default locale is the source');
        $this->assertSame([], $this->source('article')->units('missing', 'de', Scope::Needed));
    }

    public function test_previous_source_from_revisions_when_sources_absent(): void
    {
        $fp = $this->translationStatus()->fingerprint('Hello');
        $this->working('article', 'a1', ['title' => ['en' => 'Hello', 'de' => 'Hallo'], TranslationStatus::KEY => ['de' => ['title' => $fp]]]);
        $this->publisher()->publish($this->stored('article', 'a1'), 'tester');
        $this->working('article', 'a1', ['title' => ['en' => 'Hello there', 'de' => 'Hallo'], TranslationStatus::KEY => ['de' => ['title' => $fp]]]);
        $this->publisher()->publish($this->stored('article', 'a1'), 'tester');
        $this->working('article', 'a1', ['title' => ['en' => 'Hello again', 'de' => 'Hallo'], TranslationStatus::KEY => ['de' => ['title' => $fp]]]);
        $this->assertCount(2, $this->publisher()->revisions('article', 'a1'));

        $unit = $this->byId($this->source('article')->units('a1', 'de', Scope::Needed))['title'];

        $this->assertSame('Hello', $unit->previousSource, 'the newest revision whose default text has the stamped fingerprint');
        $this->assertSame('Hallo', $unit->current);

        $this->working('article', 'a2', ['title' => ['en' => 'Other', 'de' => 'Andere'], TranslationStatus::KEY => ['de' => ['title' => $this->translationStatus()->fingerprint('Never published')]]]);
        $this->assertNull($this->byId($this->source('article')->units('a2', 'de', Scope::Needed))['title']->previousSource);
    }

    public function test_opted_out_field_excluded(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'secret' => ['en' => 'Secret']]);

        $this->assertSame(['title'], array_keys($this->byId($this->source('article')->units('a1', 'de', Scope::Needed))));
        $this->assertSame(['title'], array_keys($this->byId($this->source('article')->units('a1', 'de', Scope::All))));

        $this->source('article')->write('a1', 'de', ['title' => 'Hallo', 'secret' => 'Geheim']);
        $this->assertSame(['en' => 'Secret'], $this->stored('article', 'a1')->get('secret'), 'an opted-out field is never written');
    }

    public function test_write_saves_draft_not_published_and_marks_unchecked(): void
    {
        $this->working('article', 'a1', [
            'title' => ['en' => 'Hello'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head']]],
        ]);
        $this->publisher()->publish($this->stored('article', 'a1'), 'tester');
        $scheduled = $this->stored('article', 'a1');
        $scheduled->set('_publish_at', '2099-01-01T00:00');
        $this->dm()->saveType($scheduled, false);
        $version = (int) $this->stored('article', 'a1')->get('_version');

        $outcome = $this->source('article')->write('a1', 'de', ['title' => 'Hallo', 'sections.' . self::A . '.headline' => 'Kopf', 'nope' => 'ignored']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertFalse($outcome->hadDraftChanges);
        $working = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $working->get('title'));
        $this->assertSame(['en' => 'Head', 'de' => 'Kopf'], $working->get('sections')[0]['headline']);
        $this->assertSame($version + 1, (int) $working->get('_version'));
        $this->assertSame(
            ['title', 'sections.' . self::A . '.headline'],
            array_map(static fn (TranslatableText $t): string => $t->path, $this->translationStatus()->unchecked($working->getType(), $working, 'de')),
        );
        $this->assertSame($this->translationStatus()->fingerprint('Hello'), $working->get(TranslationStatus::KEY)['de']['title'], 'stamped like a manual save');
        $this->assertSame('Head', $working->get(TranslationStatus::SOURCES)['de']['sections.' . self::A . '.headline']);
        $this->assertEmpty($working->get('_publish_at'), 'a scheduled publish never takes machine texts live unreviewed');

        $snapshot = $this->publisher()->snapshot('article', 'a1');
        $this->assertSame(['en' => 'Hello'], $snapshot?->get('title'), 'nothing is published');
        $this->assertSame(PublishState::Changed, $this->publisher()->state($working));
    }

    public function test_version_conflict_is_conflict_outcome(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], '_version' => 3]);
        $app = $this->app();
        $c = $app->container();
        $dm = $c->get(DataManager::class);
        $racing = new RacingDriver($dm->driver('json'));
        $racing->raceOnce(function (): void {
            $this->working('article', 'a1', ['title' => ['en' => 'Theirs'], '_version' => 4]);
        });
        $racingDm = new DataManager(['json' => $racing, 'default' => $dm->driver('default')], 'default', $dm->getTypeRegistry());
        $source = new FolioTextSource(
            'article',
            $c->get(TypeCatalog::class),
            $racingDm,
            $c->get(TranslationStatus::class),
            $c->get(FieldTypeRegistry::class),
            new Publisher($racingDm, $c->get(TypeCatalog::class), $c->get(RecordValidator::class)),
            new RecordLabeler(),
            $c->get(TranslationSettings::class),
        );

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo']);

        $this->assertSame(WriteOutcomeStatus::Conflict, $outcome->status);
        $this->assertNotNull($outcome->message);
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Theirs'], $stored->get('title'), 'their save is kept, never overwritten');
        $this->assertSame(4, (int) $stored->get('_version'));
    }

    public function test_a_save_between_units_and_write_is_a_conflict_and_the_human_text_survives(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum']]);
        $source = $this->source('article');
        $this->assertCount(2, $source->units('a1', 'de', Scope::Needed));

        // Someone saves while the model works: a German title of their own.
        $theirs = $this->stored('article', 'a1');
        $theirs->set('title', ['en' => 'Hello', 'de' => 'Grüss dich']);
        $this->publisher()->saveDraft($theirs, (int) $theirs->get('_version'));

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo', 'summary' => 'Summe']);

        $this->assertSame(WriteOutcomeStatus::Conflict, $outcome->status, 'written with the version units() saw');
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Grüss dich'], $stored->get('title'));
        $this->assertSame(['en' => 'Sum'], $stored->get('summary'), 'nothing of the run was written');
    }

    public function test_a_save_of_another_field_between_units_and_write_is_a_conflict(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum']]);
        $source = $this->source('article');
        $source->units('a1', 'de', Scope::Needed);

        $theirs = $this->stored('article', 'a1');
        $theirs->set('summary', ['en' => 'Sum, edited']);
        $this->publisher()->saveDraft($theirs, (int) $theirs->get('_version'));

        $this->assertSame(WriteOutcomeStatus::Conflict, $source->write('a1', 'de', ['title' => 'Hallo'])->status);
        $this->assertSame(['en' => 'Hello'], $this->stored('article', 'a1')->get('title'));
    }

    public function test_units_of_two_languages_then_two_writes_both_land(): void
    {
        $this->writeModel('article', [
            'label' => 'Articles',
            'publishing' => true,
            'fields' => ['title' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de', 'fr'], 'fallback' => 'en'];\n");
        $this->working('article', 'a1', ['title' => ['en' => 'Hello']]);
        $source = $this->source('article');
        $source->units('a1', 'de', Scope::Needed);
        $source->units('a1', 'fr', Scope::Needed);

        $this->assertSame(WriteOutcomeStatus::Written, $source->write('a1', 'de', ['title' => 'Hallo'])->status);
        $this->assertSame(WriteOutcomeStatus::Written, $source->write('a1', 'fr', ['title' => 'Bonjour'])->status, 'its own write is not a conflict');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo', 'fr' => 'Bonjour'], $this->stored('article', 'a1')->get('title'));
    }

    public function test_without_a_version_check_a_text_changed_since_units_is_left_alone(): void
    {
        $this->writeModel('memo', [
            'label' => 'Memos',
            'publishing' => false,
            'fields' => ['text' => ['type' => 'string', 'translatable' => true], 'more' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->working('memo', 'm1', ['text' => ['en' => 'Note'], 'more' => ['en' => 'More']]);
        $source = $this->source('memo');
        $this->assertCount(2, $source->units('m1', 'de', Scope::Needed));
        $this->working('memo', 'm1', ['text' => ['en' => 'Note', 'de' => 'Meine Notiz'], 'more' => ['en' => 'More']]);

        $outcome = $source->write('m1', 'de', ['text' => 'Notiz', 'more' => 'Mehr']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertSame(['text'], $outcome->leftAlone);
        $this->assertStringContainsString('text', (string) $outcome->message);
        $stored = $this->stored('memo', 'm1');
        $this->assertSame(['en' => 'Note', 'de' => 'Meine Notiz'], $stored->get('text'), 'the human text survives');
        $this->assertSame(['en' => 'More', 'de' => 'Mehr'], $stored->get('more'));
        $this->assertSame(['more'], array_keys($stored->get(TranslationStatus::MACHINE)['de']), 'only the written text is marked machine-made');

        $source->units('m1', 'de', Scope::All);
        $this->working('memo', 'm1', ['text' => ['en' => 'Note', 'de' => 'Andere'], 'more' => ['en' => 'More', 'de' => 'Noch mehr']]);
        $all = $source->write('m1', 'de', ['text' => 'Notiz', 'more' => 'Mehr']);
        $this->assertSame(WriteOutcomeStatus::Conflict, $all->status, 'every text changed: nothing written');
        $this->assertSame(['text', 'more'], $all->leftAlone);
    }

    public function test_had_draft_changes_flag(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum']]);
        $this->publisher()->publish($this->stored('article', 'a1'), 'tester');

        $this->assertFalse($this->source('article')->write('a1', 'de', ['title' => 'Hallo'])->hadDraftChanges, 'working copy equal to the snapshot');

        $outcome = $this->source('article')->write('a1', 'de', ['summary' => 'Summe']);
        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertTrue($outcome->hadDraftChanges, 'the unpublished title translation was there before');
    }

    public function test_non_publishing_type_writes_live_flag(): void
    {
        $this->assertFalse($this->source('article')->writesLive());
        $memos = $this->source('memo');
        $this->assertTrue($memos->writesLive());
        $this->working('memo', 'm1', ['text' => ['en' => 'Note']]);

        $outcome = $memos->write('m1', 'de', ['text' => 'Notiz']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertFalse($outcome->hadDraftChanges);
        $stored = $this->stored('memo', 'm1');
        $this->assertSame(['en' => 'Note', 'de' => 'Notiz'], $stored->get('text'));
        $this->assertCount(1, $this->translationStatus()->unchecked($stored->getType(), $stored, 'de'));
        $this->assertSame(WriteOutcomeStatus::Skipped, $memos->write('missing', 'de', ['text' => 'x'])->status);
    }

    public function test_registered_per_multilingual_type(): void
    {
        $this->working('article', 'a2', ['title' => ['en' => 'Second']]);
        $this->working('article', 'a1', ['title' => ['en' => 'First']]);
        $sources = $this->app()->container()->get(TextSources::class);

        foreach (['post', 'site', 'article', 'memo'] as $type) {
            $this->assertTrue($sources->has($type), $type);
        }
        $articles = $sources->get('article');
        $this->assertInstanceOf(FolioTextSource::class, $articles);
        $this->assertSame('article', $articles->collection());
        $this->assertSame('Articles', $articles->label());
        $items = $articles->items();
        sort($items);
        $this->assertSame(['a1', 'a2'], $items);
        $this->assertSame('First', $articles->title('a1'));

        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en'];\n");
        $this->assertFalse($this->app()->container()->get(TextSources::class)->has('article'), 'a single-locale app has nothing to translate');
    }

    public function test_units_and_write_on_sql(): void
    {
        $this->removeScaffold();
        $this->scaffoldPublishing('sqlite');
        $this->writeModels();
        ($this->app()->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps()['folio.tables'])();
        $this->working('article', 'a1', [
            'title' => ['en' => 'Hello'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head']]],
        ]);
        $this->publisher()->publish($this->stored('article', 'a1'), 'tester');

        $this->assertSame(['title', 'sections.' . self::A . '.headline'], array_keys($this->byId($this->source('article')->units('a1', 'de', Scope::Needed))));
        $outcome = $this->source('article')->write('a1', 'de', ['title' => 'Hallo', 'sections.' . self::A . '.headline' => 'Kopf']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $working = $this->stored('article', 'a1');
        $this->assertCount(2, $this->translationStatus()->unchecked($working->getType(), $working, 'de'));
        $this->assertSame([], $this->source('article')->units('a1', 'de', Scope::Needed), 'nothing left to translate');
        $this->assertSame(PublishState::Changed, $this->publisher()->state($working));
    }

    public function test_units_skip_fields_that_report_texts_but_cannot_accept_them(): void
    {
        $this->factsArticle();

        $units = $this->byId($this->source('article')->units('a1', 'de', Scope::All));

        $this->assertSame(['title'], array_keys($units), 'no paid call for a text that could never be written');
    }

    public function test_write_never_marks_a_path_the_field_cannot_accept(): void
    {
        $this->factsArticle('deaf_facts', 'DeafFactsFieldType');

        $outcome = $this->source('article')->write('a1', 'de', ['facts.f1.text' => 'Neu']);

        $this->assertSame(WriteOutcomeStatus::Skipped, $outcome->status);
        $stored = $this->stored('article', 'a1');
        $this->assertSame('Menschlich', $stored->get('facts')[0]['text']['de'], 'the human translation stays');
        $this->assertEmpty($stored->get(TranslationStatus::MACHINE));

        $this->source('article')->write('a1', 'de', ['title' => 'Hallo', 'facts.f1.text' => 'Neu']);
        $marks = $this->stored('article', 'a1')->get(TranslationStatus::MACHINE);
        $this->assertSame(['title'], array_keys($marks['de']));
    }

    public function test_write_ignores_paths_without_a_default_text(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello']]); // no summary in English

        $outcome = $this->source('article')->write('a1', 'de', ['summary' => 'Zusammenfassung']);

        $this->assertSame(WriteOutcomeStatus::Skipped, $outcome->status);
        $this->assertNull($this->stored('article', 'a1')->get('summary'));
    }

    private function factsArticle(string $type = 'facts', string $class = 'FactsFieldType'): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'field_types' => [\\Preflow\\Folio\\Tests\\Fixtures\\Field\\{$class}::class]];\n");
        $this->writeModel('article', [
            'label' => 'Articles',
            'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'facts' => ['type' => $type, 'translatable' => true],
            ],
        ]);
        $this->working('article', 'a1', [
            'title' => ['en' => 'Hello'],
            'facts' => [['id' => 'f1', 'text' => ['en' => 'Fact', 'de' => 'Menschlich']]],
        ]);
    }

    private function source(string $type): TextSource
    {
        return $this->app()->container()->get(TextSources::class)->get($type);
    }

    private function translationStatus(): TranslationStatus
    {
        return $this->app()->container()->get(TranslationStatus::class);
    }

    /**
     * @param list<TranslationUnit> $units
     * @return array<string, TranslationUnit>
     */
    private function byId(array $units): array
    {
        $out = [];
        foreach ($units as $unit) {
            $out[$unit->id] = $unit;
        }

        return $out;
    }
}
