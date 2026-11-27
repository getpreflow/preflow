<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Folio\Content\FolioTextSource;
use Preflow\Folio\Content\RecordLabeler;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Tests\Support\FolioPublisher;
use Preflow\Publishing\Publisher;
use Preflow\Publishing\PublishState;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Tests\Publishing\RacingDriver;
use Preflow\Folio\Tests\Support\AiScaffold;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\I18n\Machine\Scope;
use Preflow\I18n\Machine\TextFormat;
use Preflow\I18n\Machine\TextSource;
use Preflow\I18n\Machine\TextSources;
use Preflow\I18n\Machine\TranslationSettings;
use Preflow\I18n\Machine\TranslationUnit;
use Preflow\I18n\Machine\WriteOutcomeStatus;
use Preflow\Testing\ChecksFrameBalance;

final class FolioTextSourceTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;
    use AiScaffold;

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
        $this->writeModel('guide', [
            'label' => 'Guides',
            'publishing' => false,
            'frontend' => ['route' => '/guides/{slug}'],
            'fields' => ['slug' => ['type' => 'string', 'translatable' => true], 'title' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->writeModel('post', [
            'label' => 'Posts',
            'publishing' => true,
            'frontend' => ['route' => '/posts/{...slug}'],
            'fields' => ['slug' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->writeModel('badge', [
            'label' => 'Badges',
            'fields' => ['name' => ['type' => 'string'], 'active' => ['type' => 'boolean', 'translatable' => true], 'logo' => ['type' => 'string', 'hidden' => true, 'translatable' => true]],
        ]);
        $this->writeModel('pricelist', [
            'label' => 'Price lists',
            'publishing' => false,
            'fields' => ['name' => ['type' => 'string'], 'prices' => ['type' => 'table'], 'more' => ['type' => 'link']],
        ]);
        $this->writeModel('memo', [
            'label' => 'Memos',
            'publishing' => false,
            'fields' => ['text' => ['type' => 'string', 'translatable' => true]],
        ]);
    }

    protected function tearDown(): void
    {
        putenv(self::aiKeyEnv());
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

    public function test_a_field_its_show_when_hides_sends_no_unit(): void
    {
        $this->writeModel('teaser', [
            'label' => 'Teasers',
            'fields' => [
                'kind' => ['type' => 'string'],
                'title' => ['type' => 'string', 'translatable' => true],
                'button_label' => ['type' => 'string', 'translatable' => true, 'show_when' => ['field' => 'kind', '=' => 'link']],
            ],
        ]);
        $this->working('teaser', 't1', ['kind' => 'text', 'title' => ['en' => 'Hello'], 'button_label' => ['en' => 'Go']]);

        $this->assertSame(['title'], array_keys($this->byId($this->source('teaser')->units('t1', 'de', Scope::All))));

        $this->working('teaser', 't1', ['kind' => 'link', 'title' => ['en' => 'Hello'], 'button_label' => ['en' => 'Go']]);
        $this->assertSame(['title', 'button_label'], array_keys($this->byId($this->source('teaser')->units('t1', 'de', Scope::All))));
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

    public function test_a_run_saves_on_behalf_of_who_queued_it(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello']]);
        $source = $this->source('article');
        $this->assertInstanceOf(\Preflow\I18n\Machine\WritesAs::class, $source);

        $source->writeAs('a1', 'de', ['title' => 'Hallo'], 'u7');

        $row = $this->app()->container()->get(\Preflow\Core\Activity\ActivityLog::class)->recent(1)->items[0];
        $this->assertSame(['saved', 'u7', ['title']], [$row->kind->value, $row->by, $row->changed]);
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

    public function test_a_save_racing_the_write_is_retried_once_and_the_write_lands(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum'], '_version' => 3]);
        $source = $this->racingSource($racing);
        $source->units('a1', 'de', Scope::Needed);
        // Lands between write()'s read and its save, as an autosave of another field would.
        $racing->raceOnce(function (): void {
            $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum, edited'], '_version' => 4]);
        });

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status, 'retried once from a fresh read');
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $stored->get('title'));
        $this->assertSame(['en' => 'Sum, edited'], $stored->get('summary'), 'their save is kept');
        $this->assertSame(5, (int) $stored->get('_version'));
    }

    public function test_a_save_racing_the_write_twice_is_a_conflict_outcome(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], '_version' => 3]);
        $source = $this->racingSource($racing);
        $racing->raceOnce(function () use ($racing): void {
            $this->working('article', 'a1', ['title' => ['en' => 'Theirs'], '_version' => 4]);
            $racing->raceOnce(function (): void {
                $this->working('article', 'a1', ['title' => ['en' => 'Theirs again'], '_version' => 5]);
            });
        });

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo']);

        $this->assertSame(WriteOutcomeStatus::Conflict, $outcome->status);
        $this->assertNotNull($outcome->message);
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Theirs again'], $stored->get('title'), 'their save is kept, never overwritten');
        $this->assertSame(5, (int) $stored->get('_version'));
    }

    public function test_a_human_text_saved_between_units_and_write_is_left_alone_and_the_rest_is_written(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum']]);
        $source = $this->source('article');
        $this->assertCount(2, $source->units('a1', 'de', Scope::Needed));

        // Someone saves while the model works: a German title of their own.
        $theirs = $this->stored('article', 'a1');
        $theirs->set('title', ['en' => 'Hello', 'de' => 'Grüss dich']);
        $this->publisher()->saveDraft($theirs, (int) $theirs->get('_version'));

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo', 'summary' => 'Summe']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertSame(['title'], $outcome->leftAlone);
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Grüss dich'], $stored->get('title'), 'the human text wins');
        $this->assertSame(['en' => 'Sum', 'de' => 'Summe'], $stored->get('summary'));
        $this->assertSame(['summary'], array_keys($stored->get(TranslationStatus::MACHINE)['de']), 'only the written text is marked machine-made');
    }

    public function test_a_save_of_another_field_between_units_and_write_does_not_lose_the_translation(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum'], 'secret' => ['en' => 'Secret']]);
        $source = $this->source('article');
        $source->units('a1', 'de', Scope::Needed);

        // The edit form's autosave: a field the run does not translate.
        $theirs = $this->stored('article', 'a1');
        $theirs->set('secret', ['en' => 'Secret, edited']);
        $this->publisher()->saveDraft($theirs, (int) $theirs->get('_version'));
        $version = (int) $this->stored('article', 'a1')->get('_version');

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo', 'summary' => 'Summe']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $this->assertSame([], $outcome->leftAlone);
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $stored->get('title'));
        $this->assertSame(['en' => 'Sum', 'de' => 'Summe'], $stored->get('summary'));
        $this->assertSame(['en' => 'Secret, edited'], $stored->get('secret'), 'their save is kept');
        $this->assertSame($version + 1, (int) $stored->get('_version'));
        $this->assertSame(['title', 'summary'], array_keys($stored->get(TranslationStatus::MACHINE)['de']));
        $this->assertSame([], $this->translationStatus()->outdated($stored->getType(), $stored, 'de'));
    }

    public function test_a_source_text_changed_between_units_and_write_is_written_and_shows_as_outdated(): void
    {
        $this->working('article', 'a1', ['title' => ['en' => 'Hello'], 'summary' => ['en' => 'Sum']]);
        $source = $this->source('article');
        $source->units('a1', 'de', Scope::Needed);

        $theirs = $this->stored('article', 'a1');
        $theirs->set('title', ['en' => 'Hello there']);
        $this->publisher()->saveDraft($theirs, (int) $theirs->get('_version'));

        $outcome = $source->write('a1', 'de', ['title' => 'Hallo', 'summary' => 'Summe']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $stored = $this->stored('article', 'a1');
        $this->assertSame(['en' => 'Hello there', 'de' => 'Hallo'], $stored->get('title'));
        $status = $this->translationStatus();
        $this->assertSame(
            ['title'],
            array_map(static fn (TranslatableText $t): string => $t->path, $status->outdated($stored->getType(), $stored, 'de')),
            'translated from the English units() read, so it needs another look',
        );
        $this->assertSame('Hello', $status->previousSource($stored, 'de', 'title'));
        $this->assertSame('Sum', $status->previousSource($stored, 'de', 'summary'));
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

    public function test_a_translated_address_is_written_as_a_clean_address(): void
    {
        $this->working('guide', 'g1', ['slug' => ['en' => 'about-us'], 'title' => ['en' => 'About us']]);

        $outcome = $this->source('guide')->write('g1', 'de', ['slug' => 'Über uns', 'title' => 'Über uns']);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $stored = $this->stored('guide', 'g1');
        $this->assertSame(['en' => 'about-us', 'de' => 'ueber-uns'], $stored->get('slug'));
        $this->assertSame(['en' => 'About us', 'de' => 'Über uns'], $stored->get('title'), 'only address fields are changed');
    }

    public function test_a_translated_address_with_no_a_to_z_form_is_not_written(): void
    {
        $this->working('guide', 'g1', ['slug' => ['en' => 'about-us'], 'title' => ['en' => 'About us']]);

        $this->assertSame(WriteOutcomeStatus::Skipped, $this->source('guide')->write('g1', 'de', ['slug' => '!!'])->status);
        $this->assertSame(['en' => 'about-us'], $this->stored('guide', 'g1')->get('slug'));
    }

    public function test_a_translated_catch_all_address_keeps_its_slashes_in_the_draft(): void
    {
        $this->working('post', 'p1', ['slug' => ['en' => 'docs/start']]);
        $this->publisher()->publish($this->stored('post', 'p1'), 'tester');

        $this->assertSame(WriteOutcomeStatus::Written, $this->source('post')->write('p1', 'de', ['slug' => 'Doku/Erste Schritte'])->status);
        $this->assertSame(['en' => 'docs/start', 'de' => 'doku/erste-schritte'], $this->stored('post', 'p1')->get('slug'));
    }

    public function test_table_cells_reach_machine_translation_like_a_link_label(): void
    {
        // A plain-text cell (a seed, the REST API) counts as the default language's text, as a plain link label does.
        $this->working('pricelist', 'l1', [
            'name' => 'Spring',
            'prices' => ['head' => [['en' => 'Size'], 'Price'], 'rows' => [[['en' => 'Small', 'de' => 'Klein'], '10']]],
            'more' => ['kind' => 'url', 'target' => 'https://example.org', 'label' => 'More', 'new_tab' => false],
        ]);
        $this->assertTrue($this->app()->container()->get(TextSources::class)->has('pricelist'), 'a table makes the type translatable, as a link does');

        $units = $this->byId($this->source('pricelist')->units('l1', 'de', Scope::Needed));
        $this->assertSame(['prices.head.0', 'prices.head.1', 'prices.rows.0.1', 'more.label'], array_keys($units), 'the translated cell is not needed');
        $this->assertSame('Price', $units['prices.head.1']->source);
        $this->assertSame('More', $units['more.label']->source);

        $outcome = $this->source('pricelist')->write('l1', 'de', ['prices.head.0' => 'Grösse', 'prices.head.1' => 'Preis', 'prices.rows.0.1' => '10', 'more.label' => 'Mehr']);
        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $stored = $this->stored('pricelist', 'l1');
        $prices = is_string($stored->get('prices')) ? json_decode($stored->get('prices'), true) : $stored->get('prices');
        $this->assertSame(['en' => 'Size', 'de' => 'Grösse'], $prices['head'][0]);
        $this->assertSame(['en' => 'Price', 'de' => 'Preis'], $prices['head'][1]);
        $this->assertSame(['en' => 'Small', 'de' => 'Klein'], $prices['rows'][0][0]);
        $more = is_string($stored->get('more')) ? json_decode($stored->get('more'), true) : $stored->get('more');
        $this->assertSame(['en' => 'More', 'de' => 'Mehr'], $more['label']);
        $this->assertSame([], $this->translationStatus()->missing($stored->getType(), $stored, 'de'));
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

        $this->assertFalse($sources->has('badge'), 'no translatable field: not a text source');
        $this->assertNotContains('badge', $sources->collections());
        $this->assertContains('article', $sources->collections());

        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en'];\n");
        $this->assertFalse($this->app()->container()->get(TextSources::class)->has('article'), 'a single-locale app has nothing to translate');
    }

    public function test_a_read_only_type_is_not_offered_and_its_source_writes_nothing(): void
    {
        $this->writeModel('faq', [
            'label' => 'FAQ',
            'readonly' => true,
            'publishing' => false,
            'fields' => ['question' => ['type' => 'string', 'translatable' => true]],
        ]);
        $this->working('faq', 'f1', ['question' => ['en' => 'Why?']]);
        $c = $this->app()->container();

        $sources = $c->get(TextSources::class);
        $this->assertFalse($sources->has('faq'), 'editors may not change its records: not offered');
        $this->assertNotContains('faq', $sources->collections());
        $this->assertTrue($sources->has('memo'), 'an editable type still is');

        $source = new FolioTextSource(
            'faq',
            $c->get(TypeCatalog::class),
            $c->get(DataManager::class),
            $c->get(TranslationStatus::class),
            $c->get(FieldTypeRegistry::class),
            $c->get(Publisher::class),
            new RecordLabeler(),
            $c->get(TranslationSettings::class),
            $c->get(\Preflow\Publishing\RecordWriter::class),
        );
        $this->assertSame([], $source->units('f1', 'de', Scope::All), 'no text is sent to a model');
        $outcome = $source->write('f1', 'de', ['question' => 'Warum?']);
        $this->assertSame(WriteOutcomeStatus::Skipped, $outcome->status);
        $this->assertStringContainsString('read-only', (string) $outcome->message);
        $this->assertSame(['en' => 'Why?'], $this->stored('faq', 'f1')->get('question'));
    }

    #[RunInSeparateProcess]
    public function test_a_job_queued_before_its_type_became_read_only_stops_without_translating(): void
    {
        $this->aiConfig(true);
        $this->working('memo', 'm1', ['text' => ['en' => 'Hello']]);
        $runner = $this->aiApp()->container()->get(\Preflow\I18n\Machine\JobRunner::class);
        $job = $runner->plan('memo', ['de'], Scope::Needed, ['m1']);
        $runner->start($job->id, true, 'Anna');

        $this->writeModel('memo', [
            'label' => 'Memos',
            'readonly' => true,
            'publishing' => false,
            'fields' => ['text' => ['type' => 'string', 'translatable' => true]],
        ]);
        $job = $this->aiApp()->container()->get(\Preflow\I18n\Machine\JobRunner::class)->step($job->id);

        $this->assertSame('stopped', $job->state, 'marked, not crashed');
        $this->assertStringContainsString('"memo" has no text source any more', (string) $job->message);
        $this->assertSame([], $this->fake?->requests() ?? [], 'nothing was sent to the model');
        $this->assertSame(['en' => 'Hello'], $this->stored('memo', 'm1')->get('text'));
    }

    public function test_units_and_write_on_sql(): void
    {
        $this->removeScaffold();
        $this->scaffoldPublishing('sqlite');
        $this->writeModels();
        \Preflow\Folio\Tests\Support\RecordTableSteps::run($this->app());
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

    /**
     * 0.93.1: a machine's rich text is stored cleaned ("it's" as "it&#039;s", "<br>" as "<br />").
     * The write compares and marks what is stored: a block's text counts as written and every
     * text keeps its machine mark (it shows as unchecked), and a save of the same text again
     * leaves the stamps and marks.
     */
    public function test_machine_rich_text_not_in_cleaned_form_is_written_and_marked(): void
    {
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class]];\n");
        $this->writeModel('essay', [
            'label' => 'Essays',
            'publishing' => true,
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'content' => ['type' => 'richtext', 'translatable' => true],
                'sections' => ['type' => 'blocks'],
            ],
        ]);
        $this->working('essay', 'e1', [
            'title' => ['en' => 'Hello'],
            'content' => ['en' => '<p>Hi</p>'],
            'sections' => [['_uid' => self::A, '_block' => 'quote', 'text' => ['en' => '<p>Said</p>']]],
        ]);
        $quote = 'sections.' . self::A . '.text';

        $outcome = $this->source('essay')->write('e1', 'de', ['content' => "<p>Hallo, it's<br>me</p>", $quote => "<p>Gesagt, it's<br>so</p>"]);

        $this->assertSame(WriteOutcomeStatus::Written, $outcome->status);
        $working = $this->stored('essay', 'e1');
        $this->assertSame('<p>Hallo, it&#039;s<br />me</p>', $working->get('content')['de']);
        $this->assertSame('<p>Gesagt, it&#039;s<br />so</p>', $working->get('sections')[0]['text']['de']);
        $unchecked = fn (\Preflow\Data\DynamicRecord $r): array => array_map(static fn (TranslatableText $t): string => $t->path, $this->translationStatus()->unchecked($r->getType(), $r, 'de'));
        $this->assertSame(['content', $quote], $unchecked($working), 'both machine texts are marked as unchecked');
        $this->assertSame([], $this->translationStatus()->outdated($working->getType(), $working, 'de'));

        $this->dm()->saveType($working, false);
        $working = $this->stored('essay', 'e1');
        $this->assertSame(['content', $quote], $unchecked($working), 'a plain re-save keeps the marks');
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

    /** An article source on a RacingDriver, to stage a save between write()'s read and its save. */
    private function racingSource(?RacingDriver &$racing): FolioTextSource
    {
        $c = $this->app()->container();
        $dm = $c->get(DataManager::class);
        $racing = new RacingDriver($dm->driver('json'));
        $racingDm = new DataManager(['json' => $racing, 'default' => $dm->driver('default')], 'default', $dm->getTypeRegistry());

        return new FolioTextSource(
            'article',
            $c->get(TypeCatalog::class),
            $racingDm,
            $c->get(TranslationStatus::class),
            $c->get(FieldTypeRegistry::class),
            $publisher = FolioPublisher::make($racingDm, $c->get(TypeCatalog::class), $c->get(RecordValidator::class)),
            new RecordLabeler(),
            $c->get(TranslationSettings::class),
            new \Preflow\Publishing\RecordWriter($racingDm, $publisher, $c->get(\Preflow\Publishing\RecordChecks::class), $c->get(\Preflow\Publishing\SaveHooks::class)),
        );
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
