<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Content;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Preflow\Testing\ChecksFrameBalance;
use Preflow\Validation\ValidationException;

/**
 * Block values are checked by the core (BlockValueChecks, 0.92) and Folio reuses that check: its
 * RecordValidator walks no blocks itself, and its field types' rules reach the core at boot (date's
 * format, number's step, tags' limits through FieldKinds; select options through the core's
 * SelectRules). So a Folio form, an import and a REST-style saveType() refuse the same block values
 * with the same keys and messages, each message once.
 */
final class CoreBlockChecksTest extends TestCase
{
    use ChecksFrameBalance;

    use PublishingScaffold;

    private const string A = 'aaaaaaaaaaaaaaaa';
    private const string B = 'bbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('config/blocks.php', "<?php\nreturn ['classes' => ["
            . "\\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\HeroBlock\\HeroBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\TradeoffNoteBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\CheckedFieldsBlock::class, \\Preflow\\Folio\\Tests\\Fixtures\\Blocks\\LabelledPollBlock::class]];\n");
        $this->writeModel('event', ['label' => 'Events', 'publishing' => false, 'fields' => [
            'name' => ['type' => 'string', 'validate' => ['required']],
            'day' => ['type' => 'date'],
            'count' => ['type' => 'number', 'step' => 5],
            'labels' => ['type' => 'tags', 'max' => 2],
            'size' => ['type' => 'select', 'options' => ['s' => 'Small', 'l' => 'Large']],
            'sections' => ['type' => 'blocks', 'max' => 3],
            'archive' => ['type' => 'blocks', 'readonly' => true],
            'answers' => ['type' => 'tags', 'label' => ['en' => 'Possible answers', 'de' => 'Mögliche Antworten']],
            'answer' => ['type' => 'select', 'options_from' => 'answers'],
        ]]);
        $this->write('lang/en/site.php', "<?php return ['blocks' => ['poll' => ['answers' => 'Poll answers']]];");
        $this->write('lang/de/site.php', "<?php return ['blocks' => ['poll' => ['answers' => 'Umfrage-Antworten']]];");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /**
     * A record with the same invalid values at the top and in a block.
     *
     * @return array<string, mixed>
     */
    private static function invalid(): array
    {
        $bad = ['day' => '26.11.2026', 'count' => 7, 'labels' => ['a', 'b', 'c'], 'size' => 'xl'];

        return ['uuid' => 'e1', 'name' => 'Fair'] + $bad + ['sections' => [['_uid' => self::A, '_block' => 'checked_fields', 'title' => 'T'] + $bad]];
    }

    /** @return array<string, list<string>> */
    private function validatorErrors(array $values, ?\Preflow\Core\Application $app = null): array
    {
        $app ??= $this->app();
        $dm = $app->container()->get(\Preflow\Data\DataManager::class);
        try {
            $app->container()->get(RecordValidator::class)->validate(DynamicRecord::fromArray($dm->getTypeRegistry()->get('event'), $values));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    #[RunInSeparateProcess]
    public function test_each_message_appears_once(): void
    {
        $errors = $this->validatorErrors(self::invalid());

        foreach (['day', 'count', 'labels', 'size'] as $field) {
            $this->assertCount(1, $errors[$field] ?? [], $field . ': one message at the top');
            $this->assertSame($errors[$field], $errors['sections.' . self::A . '.' . $field] ?? null, $field . ': the same message, once, in the block');
        }
        $this->assertSame(['Must be one of: s, l.'], $errors['size']);
        $this->assertSame(['Format is invalid.'], $errors['day']);
    }

    #[RunInSeparateProcess]
    public function test_block_errors_and_field_errors_come_in_one_refusal(): void
    {
        $errors = $this->validatorErrors(['uuid' => 'e1', 'name' => '', 'sections' => [['_uid' => self::A, '_block' => 'checked_fields', 'title' => '']]]);

        $this->assertSame(['This field is required.'], $errors['sections.' . self::A . '.title'] ?? null);
        $this->assertSame(['This field is required.'], $errors['name'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_the_keys_and_messages_folio_showed(): void
    {
        $hero = ['_uid' => self::A, '_block' => 'hero', 'headline' => ['de' => 'nur deutsch'], 'tradeoff' => [
            ['_uid' => self::B, '_block' => 'tradeoff_note', 'chose' => 'x'],
            ['_uid' => 'cccccccccccccccc', '_block' => 'tradeoff_note', 'chose' => 'y'],
        ]];
        $errors = $this->validatorErrors(['uuid' => 'e1', 'name' => 'Fair', 'sections' => [$hero, ['_uid' => 'dddddddddddddddd'] + $hero, ['_uid' => 'eeeeeeeeeeeeeeee'] + $hero, ['_uid' => 'ffffffffffffffff'] + $hero]]);

        $this->assertSame(['At most 3 blocks allowed.'], $errors['sections'] ?? null, 'the field\'s max, keyed by the field');
        $this->assertSame(['This field is required.'], $errors['sections.' . self::A . '.headline'] ?? null, 'required binds the default language');
        $this->assertSame(['At most 1 block allowed.'], $errors['sections.' . self::A . '.tradeoff'] ?? null, 'a nested field\'s max');
    }

    #[RunInSeparateProcess]
    public function test_the_no_choices_message_names_the_source_as_folio_did(): void
    {
        // Record and block apart: 0.91 refused block errors before it checked the fields (the same messages, pinned against 0.91).
        $record = ['uuid' => 'e1', 'name' => 'Fair', 'answers' => [], 'answer' => 'Yes'];
        $block = ['uuid' => 'e1', 'name' => 'Fair', 'sections' => [['_uid' => self::A, '_block' => 'labelled_poll', 'answers' => [], 'answer' => 'Yes']]];
        // As an admin request: its middleware has set up Folio's translator (texts and their groups) before the save.
        $inAdmin = function (string $locale, array $values): array {
            $app = $this->app();

            return \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->admin($locale), function () use ($values, $app): array {
                \Preflow\Folio\FolioText::translator();

                return $this->validatorErrors($values, $app);
            });
        };

        $this->assertSame(['Noch keine Auswahl: zuerst Werte bei Mögliche Antworten eintragen.'], $inAdmin('de', $record)['answer'] ?? null, 'a record field: its label in the admin\'s language');
        $this->assertSame(['Noch keine Auswahl: zuerst Werte bei Umfrage-Antworten eintragen.'], $inAdmin('de', $block)['sections.' . self::A . '.answer'] ?? null, 'a block field: its key-shaped label translated');
        $this->assertSame(['No choices yet: add values to Possible answers first.'], $inAdmin('en', $record)['answer'] ?? null);
        $this->assertSame(['No choices yet: add values to Poll answers first.'], $inAdmin('en', $block)['sections.' . self::A . '.answer'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_a_readonly_blocks_field_is_not_checked(): void
    {
        $this->assertSame([], $this->validatorErrors(['uuid' => 'e1', 'name' => 'Fair', 'archive' => [['_uid' => self::A, '_block' => 'checked_fields', 'title' => '']]]));
    }

    #[RunInSeparateProcess]
    public function test_import_and_rest_style_save_refuses_an_invalid_block(): void
    {
        $dm = $this->dm();
        $record = DynamicRecord::fromArray($dm->getTypeRegistry()->get('event'), ['uuid' => 'e1', 'name' => 'Fair', 'sections' => [['_uid' => self::A, '_block' => 'checked_fields', 'title' => '', 'size' => 'xl', 'count' => 7]]]);
        try {
            $dm->saveType($record);
            $this->fail('an invalid block was saved');
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }

        $this->assertSame(['This field is required.'], $errors['sections.' . self::A . '.title'] ?? null);
        $this->assertSame(['Must be one of: s, l.'], $errors['sections.' . self::A . '.size'] ?? null);
        $this->assertCount(1, $errors['sections.' . self::A . '.count'] ?? [], 'Folio\'s number step, through the core');
        $this->assertNull($dm->findType('event', 'e1'), 'nothing saved');
    }

    #[RunInSeparateProcess]
    public function test_the_form_shows_the_cores_block_error(): void
    {
        $res = $this->post('/folio/event', ['name' => 'Fair', 'sections' => ['_order' => [self::A], self::A => ['_block' => 'checked_fields', 'title' => '', 'size' => 's']]]);

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('This field is required.', (string) $res->getBody());
        $this->assertNull($this->dm()->findType('event', 'e1'));
    }
}
