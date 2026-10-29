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
