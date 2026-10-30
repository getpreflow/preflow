<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Data\Locales;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Content\DraftRecordFactory;
use Preflow\Folio\Content\TranslationStatus;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AssetFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TextFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\HeroBlock\HeroBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\TradeoffNoteBlock;

final class DraftRecordFactoryTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/drf_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        mkdir($this->dir . '/uploads', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'title' => ['type' => 'string', 'translatable' => true],
                'status' => ['type' => 'string'],
                'cover' => ['type' => 'asset', 'translatable' => true],
                'sections' => ['type' => 'blocks'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/models/page.json');
        @rmdir($this->dir . '/models');
        @rmdir($this->dir . '/uploads');
        @rmdir($this->dir);
    }

    public function test_saving_de_leaves_en_untouched(): void
    {
        $record = $this->build(['title' => 'D', 'status' => 's'], ['title' => ['en' => 'E']], 'de');

        $this->assertSame(['en' => 'E', 'de' => 'D'], $record->get('title'));
    }

    public function test_saving_default_keeps_other_locales(): void
    {
        $record = $this->build(['title' => 'E2'], ['title' => ['en' => 'E', 'de' => 'D']], 'en');

        $this->assertSame(['en' => 'E2', 'de' => 'D'], $record->get('title'));
    }

    public function test_legacy_scalar_becomes_map(): void
    {
        $record = $this->build(['title' => 'D'], ['title' => 'Old english'], 'de');

        $this->assertSame(['en' => 'Old english', 'de' => 'D'], $record->get('title'));
    }

    public function test_shared_field_is_plain_value(): void
    {
        $this->assertSame('published', $this->build(['title' => 'X', 'status' => 'published'], [], 'de')->get('status'));
    }

    public function test_upload_fields_are_always_shared(): void
    {
        $record = $this->build(['title' => 'X'], ['cover' => '2026/10/a.png'], 'de');

        $this->assertSame('2026/10/a.png', $record->get('cover'));
    }

    public function test_locales_checkbox_handling(): void
    {
        $checked = $this->build(['title' => 'X', '_locales_submitted' => '1', '_locales' => ['de', 'xx']], [], 'en');
        $kept = $this->build(['title' => 'X'], ['_locales' => ['en', 'de']], 'en');
        $none = $this->build(['title' => 'X', '_locales_submitted' => '1'], ['_locales' => ['en', 'de']], 'en');

        $this->assertSame(['en', 'de'], $checked->get('_locales'));
        $this->assertSame(['en', 'de'], $kept->get('_locales'));
        $this->assertSame(['en'], $none->get('_locales'));
    }

    public function test_system_keys_are_carried_over(): void
    {
        $this->assertSame(30, $this->build(['title' => 'X'], ['_order' => 30], 'en')->get('_order'));
    }

    public function test_unknown_locale_uses_default(): void
    {
        $record = $this->build(['title' => 'X'], ['title' => ['en' => 'E']], 'xx');

        $this->assertSame(['en' => 'X'], $record->get('title'));
    }

    public function test_single_locale_stores_scalars(): void
    {
        $record = $this->build(['title' => 'Plain'], [], 'en', Locales::single('en'));

        $this->assertSame('Plain', $record->get('title'));
        $this->assertNull($record->get('_locales'));
    }

    public function test_preview_mode_writes_no_uploads(): void
    {
        $file = (new Psr17Factory())->createUploadedFile(
            (new Psr17Factory())->createStream('png-bytes'), 9, UPLOAD_ERR_OK, 'x.png', 'image/png',
        );
        $request = (new Psr17Factory())->createServerRequest('POST', '/')
            ->withParsedBody(['title' => 'X'])->withUploadedFiles(['cover' => $file]);

        $record = $this->factory()->build($this->type(), $request, [], 'en', writeUploads: false);

        $this->assertSame([], glob($this->dir . '/uploads/*') ?: []);
        $this->assertSame('', (string) $record->get('cover'));
    }

    public function test_saving_a_translation_stamps_its_fingerprint(): void
    {
        $record = $this->build(['title' => 'Hallo'], ['title' => ['en' => 'Hello']], 'de');

        $this->assertSame(['de' => ['title' => $this->fingerprint('Hello')]], $record->get('_translated_from'));
    }

    public function test_saving_the_default_stamps_nothing(): void
    {
        $record = $this->build(['title' => 'Hello'], ['title' => ['en' => 'Hi', 'de' => 'Hallo']], 'en');

        $this->assertNull($record->get('_translated_from'));
    }

    public function test_unchanged_translation_keeps_old_fingerprint(): void
    {
        $from = ['de' => ['title' => $this->fingerprint('Hello')]];

        $onEnglish = $this->build(['title' => 'Hello!'], ['title' => ['en' => 'Hello', 'de' => 'Hallo'], '_translated_from' => $from], 'en');
        $onGerman = $this->build(['title' => 'Hallo'], ['title' => ['en' => 'Hello!', 'de' => 'Hallo'], '_translated_from' => $from], 'de');

        $this->assertSame($from, $onEnglish->get('_translated_from'));
        $this->assertSame($from, $onGerman->get('_translated_from'), 'saving the same German text again is no new translation');
        $this->assertSame(['title'], array_map(
            static fn ($t) => $t->path,
            (new TranslationStatus($this->registry()))->outdated($this->type(), $onEnglish, 'de'),
        ));
    }

    public function test_changed_translation_takes_current_default(): void
    {
        $from = ['de' => ['title' => $this->fingerprint('Hello')]];

        $record = $this->build(['title' => 'Hallo!'], ['title' => ['en' => 'Hello!', 'de' => 'Hallo'], '_translated_from' => json_encode($from)], 'de');

        $this->assertSame(['de' => ['title' => $this->fingerprint('Hello!')]], $record->get('_translated_from'));
    }

    public function test_default_and_translation_written_together_take_new_default(): void
    {
        // A block added on the German tab writes the German text and the same English one.
        $record = $this->build(['title' => 'Hallo', 'sections' => [
            '_order' => [self::A],
            self::A => ['_block' => 'hero', 'headline' => 'Neu'],
        ]], ['title' => ['en' => 'Hello']], 'de');

        $this->assertSame(['de' => 'Neu', 'en' => 'Neu'], $record->get('sections')[0]['headline']);
        $this->assertSame($this->fingerprint('Neu'), $record->get('_translated_from')['de']['sections.' . self::A . '.headline']);
    }

    public function test_deleted_block_drops_its_fingerprints(): void
    {
        $existing = [
            'title' => ['en' => 'Hello', 'de' => 'Hallo'],
            'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head', 'de' => 'Kopf']]],
            '_translated_from' => ['de' => ['title' => $this->fingerprint('Hello'), 'sections.' . self::A . '.headline' => $this->fingerprint('Head')]],
        ];

        $record = $this->build(['title' => 'Hello', 'sections' => ['_order' => []]], $existing, 'en');

        $this->assertSame(['de' => ['title' => $this->fingerprint('Hello')]], $record->get('_translated_from'));
    }

    public function test_single_locale_writes_no_fingerprints(): void
    {
        $this->assertNull($this->build(['title' => 'Plain'], [], 'en', Locales::single('en'))->get('_translated_from'));
    }

    public function test_saving_a_translation_stores_its_source_text(): void
    {
        $record = $this->build(['title' => 'Hallo'], ['title' => ['en' => 'Hello']], 'de');

        $this->assertSame(['de' => ['title' => 'Hello']], $record->get(TranslationStatus::SOURCES));
        $this->assertNull($record->get(TranslationStatus::MACHINE), 'nothing posted as AI-filled');
    }

    public function test_posted_ai_unchecked_paths_marked_on_build(): void
    {
        $record = $this->build(
            ['title' => 'Hallo', 'sections' => ['_order' => [self::A], self::A => ['_block' => 'hero', 'headline' => 'Kopf']], '_ai_unchecked' => ['title', 'sections.' . self::A . '.headline', 'gone', 7]],
            ['title' => ['en' => 'Hello'], 'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head']]]],
            'de',
        );

        $this->assertSame(
            ['de' => ['title' => $this->fingerprint('Hallo'), 'sections.' . self::A . '.headline' => $this->fingerprint('Kopf')]],
            $record->get(TranslationStatus::MACHINE),
        );
        $this->assertSame(['title', 'sections.' . self::A . '.headline'], $this->unchecked($record, 'de'));
    }

    public function test_resave_without_change_keeps_mark(): void
    {
        $first = $this->build(['title' => 'Hallo', '_ai_unchecked' => ['title']], ['title' => ['en' => 'Hello']], 'de');
        $stored = ['title' => $first->get('title'), TranslationStatus::KEY => $first->get(TranslationStatus::KEY), TranslationStatus::SOURCES => $first->get(TranslationStatus::SOURCES), TranslationStatus::MACHINE => json_encode($first->get(TranslationStatus::MACHINE))];

        $resaved = $this->build(['title' => 'Hallo'], $stored, 'de');
        $otherLocale = $this->build(['title' => 'Hello'], $stored, 'en');
        $edited = $this->build(['title' => 'Hallo du'], $stored, 'de');

        $this->assertSame(['title'], $this->unchecked($resaved, 'de'), 'a plain re-save keeps the mark');
        $this->assertSame(['title'], $this->unchecked($otherLocale, 'de'), 'saving another locale keeps it too');
        $this->assertSame([], $this->unchecked($edited, 'de'), 'changing the text clears it');
        $this->assertSame([], $edited->get(TranslationStatus::MACHINE));
    }

    public function test_posted_ai_checked_paths_clear_marks_on_build(): void
    {
        $head = 'sections.' . self::A . '.headline';
        $form = ['title' => 'Hallo', 'sections' => ['_order' => [self::A], self::A => ['_block' => 'hero', 'headline' => 'Kopf']]];
        $first = $this->build($form + ['_ai_unchecked' => ['title', $head]], ['title' => ['en' => 'Hello'], 'sections' => [['_uid' => self::A, '_block' => 'hero', 'headline' => ['en' => 'Head']]]], 'de');
        $stored = ['title' => $first->get('title'), 'sections' => $first->get('sections'), TranslationStatus::KEY => $first->get(TranslationStatus::KEY), TranslationStatus::SOURCES => $first->get(TranslationStatus::SOURCES), TranslationStatus::MACHINE => json_encode($first->get(TranslationStatus::MACHINE))];
        $this->assertSame(['title', $head], $this->unchecked($first, 'de'));

        // Accepted after the autosave marked it: the text is unchanged, the posted list clears the mark.
        $accepted = $this->build($form + ['_ai_checked' => [$head, 'gone', 7]], $stored, 'de');
        $otherLocale = $this->build(['title' => 'Hello', 'sections' => ['_order' => [self::A], self::A => ['_block' => 'hero', 'headline' => 'Head']], '_ai_checked' => ['title']], $stored, 'en');

        $this->assertSame(['title'], $this->unchecked($accepted, 'de'));
        $this->assertSame(['title', $head], $this->unchecked($otherLocale, 'de'), 'a save of another locale clears nothing in de');
    }

    /** @return list<string> */
    private function unchecked(\Preflow\Data\DynamicRecord $record, string $locale): array
    {
        return array_map(
            static fn ($t) => $t->path,
            (new TranslationStatus($this->registry()))->unchecked($this->type(), $record, $locale),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $existing
     */
    private function build(array $body, array $existing, string $locale, ?Locales $locales = null): \Preflow\Data\DynamicRecord
    {
        $request = (new Psr17Factory())->createServerRequest('POST', '/')->withParsedBody($body);

        return $this->factory()->build($this->type($locales), $request, ['uuid' => 'p1'] + $existing, $locale);
    }

    private function type(?Locales $locales = null): TypeDefinition
    {
        return (new TypeRegistry($this->dir . '/models', $locales ?? new Locales(['en', 'de'], 'en')))->get('page');
    }

    private function factory(): DraftRecordFactory
    {
        return new DraftRecordFactory($this->registry());
    }

    private function registry(): FieldTypeRegistry
    {
        $registry = new FieldTypeRegistry();
        $registry->register(new StringFieldType());
        $registry->register(new TextFieldType());
        $registry->register(new AssetFieldType($this->dir . '/uploads', '/folio/_uploads'));
        $registry->register(new BlocksFieldType(new BlockRegistry([HeroBlock::class, TradeoffNoteBlock::class]), $registry));

        return $registry;
    }

    private function fingerprint(string $text): string
    {
        return substr(hash('sha256', trim($text)), 0, 16);
    }
}
