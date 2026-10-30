<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeDefinition;
use Preflow\Folio\Content\TranslatableText;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Tests\Support\PublishingScaffold;

final class TranslationStatusTest extends TestCase
{
    use PublishingScaffold;

    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_plain_field_missing_when_default_has_text_and_locale_empty(): void
    {
        $this->assertSame(['title'], $this->paths($this->translationStatus()->missing($this->def(), $this->record(['title' => ['en' => 'Hello']]), 'de')));
        $this->assertSame([], $this->paths($this->translationStatus()->missing($this->def(), $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo']]), 'de')));
        $this->assertSame([], $this->paths($this->translationStatus()->missing($this->def(), $this->record(['title' => ['en' => '']]), 'de')));
        $this->assertSame([], $this->paths($this->translationStatus()->missing($this->def(), $this->record(['title' => ['en' => '  ', 'de' => '']]), 'de')));
    }

    public function test_texts_cover_every_translatable_field_and_label_them(): void
    {
        $texts = $this->translationStatus()->texts($this->def(), $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => 'Plain english']));
        $byPath = [];
        foreach ($texts as $text) {
            $byPath[$text->path] = $text;
        }

        $this->assertSame(['title', 'slug', 'body'], array_keys($byPath));
        $this->assertSame('Title', $byPath['title']->label);
        $this->assertSame(['en' => 'Hello', 'de' => 'Hallo'], $byPath['title']->values);
        $this->assertSame(['en' => 'Plain english', 'de' => null], $byPath['body']->values, 'a legacy scalar is the default text');
    }

    public function test_block_leaves_are_walked_with_uid_paths(): void
    {
        $record = $this->record(['title' => ['en' => 'T', 'de' => 'T'], 'sections' => [
            ['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'A'], 'intro' => ['en' => ''], 'tradeoff' => [
                ['_uid' => self::B, '_block' => 'tradeoff_note', 'chose' => ['en' => 'Speed', 'de' => 'Tempo']],
            ]],
            ['_uid' => 'cccccccccccccccc', '_block' => 'gone', 'headline' => ['en' => 'Unknown']],
        ]]);

        $missing = $this->translationStatus()->missing($this->def(), $record, 'de');

        $this->assertSame(['sections.' . self::A . '.headline'], $this->paths($missing));
        $this->assertStringContainsString('›', $missing[0]->label);
        $this->assertSame('Sections › Hero › Headline', $missing[0]->label);
        $this->assertContains(
            'sections.' . self::A . '.tradeoff.' . self::B . '.chose',
            $this->paths($this->translationStatus()->texts($this->def(), $record)),
        );
    }

    public function test_custom_field_type_reports_its_texts(): void
    {
        $def = $this->def(withFacts: true);
        $record = DynamicRecord::fromArray($def, ['uuid' => 'p1', 'title' => ['en' => 'T', 'de' => 'T'], 'facts' => [
            ['id' => 'f1', 'text' => ['en' => 'One']],
            ['id' => 'f2', 'text' => ['en' => 'Two', 'de' => '']],
        ]]);

        $missing = $this->translationStatus()->missing($def, $record, 'de');

        $this->assertSame(['facts.f1.text', 'facts.f2.text'], $this->paths($missing));
        $this->assertSame('Facts › Fact f1', $missing[0]->label);
    }

    public function test_outdated_when_default_changed_after_translation(): void
    {
        $status = $this->translationStatus();
        $from = ['de' => ['title' => $status->fingerprint('Hello')]];

        $changed = $this->record(['title' => ['en' => 'Hello!', 'de' => 'Hallo'], '_translated_from' => $from]);
        $same = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], '_translated_from' => $from]);
        $sqlText = $this->record(['title' => ['en' => 'Hello!', 'de' => 'Hallo'], '_translated_from' => json_encode($from)]);
        $none = $this->record(['title' => ['en' => 'Hello!', 'de' => 'Hallo']]);

        $this->assertSame(['title'], $this->paths($status->outdated($this->def(), $changed, 'de')));
        $this->assertSame([], $this->paths($status->outdated($this->def(), $same, 'de')));
        $this->assertSame(['title'], $this->paths($status->outdated($this->def(), $sqlText, 'de')), 'stored as JSON text');
        $this->assertSame([], $this->paths($status->outdated($this->def(), $none, 'de')));
    }

    public function test_fingerprint_is_trimmed_sha256_prefix(): void
    {
        $status = $this->translationStatus();

        $this->assertSame(substr(hash('sha256', 'Hello'), 0, 16), $status->fingerprint(' Hello '));
        $this->assertSame(substr(hash('sha256', ''), 0, 16), $status->fingerprint(null));
    }

    public function test_confirm_sets_current_fingerprints(): void
    {
        $status = $this->translationStatus();
        $old = $status->fingerprint('Old');
        $record = $this->record([
            'title' => ['en' => 'New', 'de' => 'Neu'],
            'body' => ['en' => 'Body'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head', 'de' => 'Kopf']]],
            '_translated_from' => ['de' => ['title' => $old, 'sections.' . self::A . '.headline' => $old]],
        ]);

        $sectionsOnly = $status->confirm($this->def(), $record, 'de', 'sections');
        $this->assertSame(['title'], $this->paths($status->outdated($this->def(), $sectionsOnly, 'de')));
        $this->assertSame($status->fingerprint('Head'), $sectionsOnly->get('_translated_from')['de']['sections.' . self::A . '.headline']);
        $this->assertSame(['title', 'sections.' . self::A . '.headline'], $this->paths($status->outdated($this->def(), $record, 'de')), 'the given record is left alone');

        $all = $status->confirm($this->def(), $record, 'de');
        $this->assertSame([], $this->paths($status->outdated($this->def(), $all, 'de')));
        $this->assertArrayNotHasKey('body', $all->get('_translated_from')['de'], 'only texts the locale has are confirmed');
    }

    public function test_single_locale_app_has_no_texts(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en'], 'fallback' => 'en'];\n");

        $this->assertSame([], $this->translationStatus()->texts($this->def(), $this->record(['title' => 'Hello'])));
    }

    public function test_autosave_on_translation_stamps_fingerprint(): void
    {
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $res = $this->post('/folio/post/p1/autosave', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => '0']);

        $this->assertSame(200, $res->getStatusCode());
        $from = $this->stored('post', 'p1')->get('_translated_from');
        $from = is_string($from) ? json_decode($from, true) : $from;
        $this->assertSame($this->translationStatus()->fingerprint('Hello'), $from['de']['title']);
    }

    public function test_translatable_booleans_are_not_texts(): void
    {
        $this->writeModel('post', ['fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'featured' => ['type' => 'boolean', 'translatable' => true],
        ]]);
        $record = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'featured' => ['en' => true, 'de' => false]]);

        $this->assertSame(['title'], $this->paths($this->translationStatus()->texts($this->def(), $record)));
        $this->assertSame([], $this->paths($this->translationStatus()->missing($this->def(), $record, 'de')));
    }

    public function test_readonly_and_hidden_fields_are_not_texts(): void
    {
        $this->writeModel('post', ['fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'code' => ['type' => 'string', 'translatable' => true, 'readonly' => true],
            'internal' => ['type' => 'string', 'translatable' => true, 'hidden' => true],
        ]]);
        $record = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'code' => ['en' => 'X'], 'internal' => ['en' => 'Y']]);

        $this->assertSame(['title'], $this->paths($this->translationStatus()->texts($this->def(), $record)));
        $this->assertSame([], $this->paths($this->translationStatus()->missing($this->def(), $record, 'de')));
    }

    public function test_stamp_stores_source_text_next_to_fingerprint(): void
    {
        $status = $this->translationStatus();
        $before = $this->record(['title' => ['en' => 'Hello'], 'body' => ['en' => 'Body', 'de' => 'Rumpf']]);
        $after = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => ['en' => 'Body', 'de' => 'Rumpf']]);

        $stamped = $status->stamp($this->def(), $before, $after, 'de');

        $this->assertSame(['de' => ['title' => $status->fingerprint('Hello')]], $stamped->get(TranslationStatus::KEY));
        $this->assertSame(['de' => ['title' => 'Hello']], $stamped->get(TranslationStatus::SOURCES), 'unchanged texts get no source');
        $this->assertSame('Hello', $status->previousSource($stamped, 'de', 'title'));
        $this->assertNull($status->previousSource($stamped, 'de', 'body'));

        $asSqlText = $this->record(['title' => ['en' => 'Hello!', 'de' => 'Hallo'], TranslationStatus::SOURCES => json_encode(['de' => ['title' => 'Hello']])]);
        $this->assertSame('Hello', $status->previousSource($asSqlText, 'de', 'title'), 'stored as JSON text');

        $cleared = $status->stamp($this->def(), $stamped, $this->record([
            'title' => ['en' => 'Hello'],
            TranslationStatus::KEY => $stamped->get(TranslationStatus::KEY),
            TranslationStatus::SOURCES => $stamped->get(TranslationStatus::SOURCES),
        ]), 'de');
        $this->assertSame([], $cleared->get(TranslationStatus::SOURCES), 'a cleared translation loses its source');
        $this->assertNull($status->previousSource($cleared, 'de', 'title'));
    }

    public function test_stamp_drops_sources_of_vanished_texts(): void
    {
        $status = $this->translationStatus();
        $sources = ['de' => ['title' => 'Hello', 'sections.' . self::A . '.headline' => 'Head']];
        $record = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], TranslationStatus::SOURCES => $sources]);

        $stamped = $status->stamp($this->def(), $record, clone $record, 'en');

        $this->assertSame(['de' => ['title' => 'Hello']], $stamped->get(TranslationStatus::SOURCES));
    }

    public function test_confirm_sets_source_and_clears_machine_marks(): void
    {
        $status = $this->translationStatus();
        $record = $status->markMachine($this->def(), $this->record([
            'title' => ['en' => 'New', 'de' => 'Neu'],
            'body' => ['en' => 'Body', 'de' => 'Rumpf'],
            TranslationStatus::SOURCES => ['de' => ['title' => 'Old', 'body' => 'Old body']],
        ]), 'de', ['title', 'body']);
        $this->assertSame(['title', 'body'], $this->paths($status->unchecked($this->def(), $record, 'de')));

        $titleOnly = $status->confirm($this->def(), $record, 'de', 'title');

        $this->assertSame(['title' => 'New', 'body' => 'Old body'], $titleOnly->get(TranslationStatus::SOURCES)['de']);
        $this->assertSame(['body'], $this->paths($status->unchecked($this->def(), $titleOnly, 'de')));
        $this->assertSame([], $this->paths($status->unchecked($this->def(), $status->confirm($this->def(), $record, 'de'), 'de')));
        $this->assertSame(['title', 'body'], $this->paths($status->unchecked($this->def(), $record, 'de')), 'the given record is left alone');
    }

    public function test_unchecked_until_edited(): void
    {
        $status = $this->translationStatus();
        $def = $this->def();
        $record = $status->markMachine($def, $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], 'body' => ['en' => 'Body']]), 'de', ['title', 'body', 'nope']);

        $this->assertSame(['title'], $this->paths($status->unchecked($def, $record, 'de')), 'only texts the locale has are marked; unknown paths are ignored');
        $this->assertSame([], $this->paths($status->unchecked($def, $record, 'en')));

        $resaved = $status->stamp($def, $record, $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], TranslationStatus::MACHINE => $record->get(TranslationStatus::MACHINE)]), 'de');
        $this->assertSame(['title'], $this->paths($status->unchecked($def, $resaved, 'de')), 'the same text again stays unchecked');

        $edited = $status->stamp($def, $resaved, $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo!'], TranslationStatus::MACHINE => $resaved->get(TranslationStatus::MACHINE)]), 'de');
        $this->assertSame([], $this->paths($status->unchecked($def, $edited, 'de')));
        $this->assertSame([], $edited->get(TranslationStatus::MACHINE), 'the stale mark is pruned');

        $asSqlText = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo'], TranslationStatus::MACHINE => json_encode($record->get(TranslationStatus::MACHINE))]);
        $this->assertSame(['title'], $this->paths($status->unchecked($def, $asSqlText, 'de')), 'stored as JSON text');
    }

    public function test_mark_machine_leaves_default_locale_alone(): void
    {
        $status = $this->translationStatus();
        $record = $this->record(['title' => ['en' => 'Hello', 'de' => 'Hallo']]);

        $marked = $status->markMachine($this->def(), $record, 'en', ['title']);

        $this->assertNotSame($record, $marked, 'a copy, as for any other locale');
        $this->assertSame($record->toArray(), $marked->toArray(), 'the default text is the source, never machine-made');
        $this->assertSame([], $this->paths($status->unchecked($this->def(), $marked, 'en')));
    }

    public function test_rich_text_reported_as_html(): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'blocks' => ['classes' => [\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\QuoteBlock::class]]];\n");
        $this->writeModel('post', ['fields' => [
            'title' => ['type' => 'string', 'translatable' => true],
            'content' => ['type' => 'richtext', 'translatable' => true],
            'sections' => ['type' => 'blocks'],
        ]]);
        $record = $this->record(['title' => ['en' => 'Hello'], 'content' => ['en' => '<p>Hi</p>'], 'sections' => [
            ['_uid' => self::A, '_block' => 'quote', 'text' => ['en' => '<p>Said</p>']],
        ]]);

        $formats = [];
        foreach ($this->translationStatus()->texts($this->def(), $record) as $text) {
            $formats[$text->path] = $text->format;
        }

        $this->assertSame(['title' => 'plain', 'content' => 'html', 'sections.' . self::A . '.text' => 'html'], $formats);
    }

    public function test_within_keeps_kind_and_format(): void
    {
        $text = (new TranslatableText('opt', 'Option', ['en' => 'A'], 'quiz-option', 'html'))->within('flow.s1', 'Flow');
        $plain = new TranslatableText('t', 'T', []);

        $this->assertSame(['flow.s1.opt', 'Flow › Option', 'quiz-option', 'html'], [$text->path, $text->label, $text->kind, $text->format]);
        $this->assertSame([null, 'plain'], [$plain->kind, $plain->format]);
    }

    public function test_new_keys_persist_on_sql(): void
    {
        $this->removeScaffold();
        $this->scaffoldPublishing('sqlite');
        ($this->app()->container()->get(\Preflow\Data\Migration\MigrationRegistry::class)->steps()['folio.tables'])();
        $this->working('post', 'p1', ['title' => ['en' => 'Hello'], 'slug' => ['en' => 'hello']]);

        $res = $this->post('/folio/post/p1/autosave', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => '0']);

        $this->assertSame(200, $res->getStatusCode());
        $status = $this->translationStatus();
        // Marked as a translation job saves its texts.
        $this->dm()->saveType($status->markMachine($this->def(), $this->stored('post', 'p1'), 'de', ['title']), false);
        $stored = $this->stored('post', 'p1');
        $this->assertSame('Hello', $status->previousSource($stored, 'de', 'title'));
        $this->assertSame('hello', $status->previousSource($stored, 'de', 'slug'));
        $this->assertSame(['title'], $this->paths($status->unchecked($this->def(), $stored, 'de')));

        $version = (string) $stored->get('_version');
        $this->assertSame(200, $this->post('/folio/post/p1/autosave', ['title' => 'Hallo', 'slug' => 'hallo', '_locale' => 'de', '_version' => $version])->getStatusCode());
        $this->assertSame(['title'], $this->paths($status->unchecked($this->def(), $this->stored('post', 'p1'), 'de')), 'a re-save keeps the mark');
    }

    private function translationStatus(): TranslationStatus
    {
        return $this->app()->container()->get(TranslationStatus::class);
    }

    private function def(bool $withFacts = false): TypeDefinition
    {
        if ($withFacts) {
            $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'field_types' => [\\Preflow\\Folio\\Tests\\Fixtures\\Field\\FactsFieldType::class]];\n");
            $this->writeModel('post', ['fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'facts' => ['type' => 'facts'],
            ]]);
        }

        return $this->dm()->getTypeRegistry()->get('post');
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): DynamicRecord
    {
        return DynamicRecord::fromArray($this->def(), ['uuid' => 'p1'] + $data);
    }

    /**
     * @param list<TranslatableText> $texts
     * @return list<string>
     */
    private function paths(array $texts): array
    {
        return array_map(static fn (TranslatableText $t): string => $t->path, $texts);
    }
}
