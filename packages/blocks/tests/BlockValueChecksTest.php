<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Blocks\BlockValueChecks;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Message;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;

/**
 * Block field values are checked by the core on every validated write (0.92), through a booted app
 * without Folio: BlocksServiceProvider registers BlockValueChecks as a record check, so a seed, an
 * import or a REST save of a page is refused for a block value Folio's form would refuse, with the
 * same keys ("<field>.<uid>.<leaf>", "_areas.<area>.<uid>.<leaf>") and messages.
 */
final class BlockValueChecksTest extends BlocksAppTestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';
    private const C = 'cccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        $this->write('config/blocks.php', "<?php\nreturn [\n"
            . "    'classes' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\Section::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\SectionQuote::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Checks\\QuoteList::class],\n"
            . "    'layouts' => [\\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Standard::class, \\Preflow\\Blocks\\Tests\\Fixtures\\Layouts\\Promo::class],\n"
            . "];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'sections' => ['type' => 'blocks']],
        ]));
        $this->write('config/models/counted.json', (string) json_encode([
            'key' => 'counted', 'table' => 'counted', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['items' => ['type' => 'blocks', 'min' => 1, 'max' => 2], 'lists' => ['type' => 'blocks']],
        ]));
        $this->write('config/models/landing.json', (string) json_encode([
            'key' => 'landing', 'table' => 'landing', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
            'layouts' => ['allowed' => ['promo', 'standard'], 'default' => 'standard'],
        ]));
    }

    public function test_the_checks_are_bound(): void
    {
        $this->assertInstanceOf(BlockValueChecks::class, $this->app()->container()->get(BlockValueChecks::class));
    }

    public function test_required_block_field_is_refused_without_folio(): void
    {
        $record = DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p1', 'title' => 'About', 'sections' => [self::section(self::A, ['title' => ['en' => '', 'de' => 'Nur deutsch']])]]);
        try {
            $this->data()->saveType($record);
            $this->fail('a block without its required title was saved');
        } catch (ValidationException $e) {
            $this->assertSame(['sections.' . self::A . '.title' => ['This field is required.']], $e->errors(), 'required binds the default language');
        }
        $this->assertNull($this->data()->findType('page', 'p1'), 'nothing saved');

        $this->data()->saveType(DynamicRecord::fromArray($this->types()->get('page'), ['uuid' => 'p1', 'sections' => json_encode([self::section(self::A)])]));
        $this->assertNotNull($this->data()->findType('page', 'p1'), 'a valid block saves (JSON text too)');
    }

    public function test_nested_block_error_keys(): void
    {
        $quotes = [['_uid' => self::B, '_block' => 'section_quote', 'text' => 'Said'], ['_uid' => self::C, '_block' => 'section_quote', 'text' => '']];

        $this->assertSame(
            ['sections.' . self::A . '.quotes.' . self::C . '.text' => ['This field is required.']],
            $this->errors('page', ['sections' => [self::section(self::A, ['quotes' => $quotes])]]),
        );
    }

    public function test_select_option_inside_a_block_is_checked(): void
    {
        $this->assertSame(['sections.' . self::A . '.size' => ['Must be one of: s, l.']], $this->errors('page', ['sections' => [self::section(self::A, ['size' => 'xl'])]]));
        $this->assertSame([], $this->errors('page', ['sections' => [self::section(self::A, ['size' => 'l'])]]));
    }

    public function test_options_from_reads_the_same_blocks_values(): void
    {
        $sections = [
            self::section(self::A, ['answers' => ['Yes', 'No'], 'answer' => 'No']),
            self::section(self::B, ['answers' => ['Red', 'Blue'], 'answer' => 'Yes']),
            self::section(self::C, ['answers' => [], 'answer' => 'Yes']),
        ];

        $this->assertSame([
            'sections.' . self::B . '.answer' => ['Must be one of: Red, Blue.'],
            'sections.' . self::C . '.answer' => ['No choices yet: add values to Possible answers first.'],
        ], $this->errors('page', ['sections' => $sections]), 'each block against its own values');
    }

    public function test_field_kind_rules_apply_inside_blocks(): void
    {
        $this->data()->fieldKinds()->rules('link', static fn (array $config): array => [new class implements ValidationRule {
            public function validate(mixed $value, ValidationContext $context): true|string|Message
            {
                return is_string($value) && str_starts_with($value, 'javascript:') ? 'Not a safe link.' : true;
            }
        }]);

        $this->assertSame(['sections.' . self::A . '.website' => ['Not a safe link.']], $this->errors('page', ['sections' => [self::section(self::A, ['website' => 'javascript:alert(1)'])]]));
        $this->assertSame([], $this->errors('page', ['sections' => [self::section(self::A, ['website' => 'https://example.com'])]]));
    }

    public function test_hidden_block_field_is_not_checked(): void
    {
        $this->assertSame([], $this->errors('page', ['sections' => [self::section(self::A, ['kind' => 'text', 'button_url' => ''])]]), 'hidden: not required');
        $this->assertSame(['sections.' . self::B . '.button_url' => ['This field is required.']], $this->errors('page', ['sections' => [self::section(self::B, ['kind' => 'link', 'button_url' => ''])]]), 'shown: required');
    }

    public function test_unplaced_area_blocks_are_not_checked(): void
    {
        // "standard" has only "main": the "side" area is kept, not shown, and not checked.
        $this->assertSame([], $this->errors('landing', ['_layout' => 'standard', '_areas' => ['main' => [self::section(self::A)], 'side' => [self::section(self::B, ['title' => ''])]]]));
    }

    public function test_area_block_error_keys(): void
    {
        $this->assertSame(
            ['_areas.main.' . self::A . '.title' => ['This field is required.']],
            $this->errors('landing', ['_layout' => 'promo', '_areas' => json_encode(['hero' => [], 'main' => [self::section(self::A, ['title' => ''])]])]),
        );
    }

    public function test_readonly_field_given_no_rules_is_skipped(): void
    {
        $values = ['sections' => [self::section(self::A, ['title' => ''])]];

        $this->assertSame([], $this->errors('page', $values, ['sections' => []]), 'a field the caller gives no rules (Folio\'s read-only fields) is not checked');
        $this->assertSame(['sections.' . self::A . '.title'], array_keys($this->errors('page', $values, ['title' => []])), 'rules given for another field do not skip it');
    }

    public function test_unknown_blocks_are_skipped(): void
    {
        $this->assertSame([], $this->errors('page', ['sections' => [['_uid' => self::A, '_block' => 'retired', 'title' => '']]]));
    }

    public function test_block_count_without_folio(): void
    {
        $quotes = static fn (int $n): array => array_map(static fn (int $i): array => ['_uid' => str_repeat(dechex($i + 1), 16), '_block' => 'section_quote', 'text' => 'Said'], $n === 0 ? [] : range(0, $n - 1));
        $values = static fn (int $n): array => ['items' => $quotes($n), 'lists' => [['_uid' => self::A, '_block' => 'quote_list', 'quotes' => $quotes($n)]]];
        $errors = function (array $values): array {
            $errors = $this->errors('counted', $values);
            ksort($errors);

            return $errors;
        };

        $this->assertSame(
            ['items' => ['Add at least 1 block.'], 'lists.' . self::A . '.quotes' => ['Add at least 1 block.']],
            $errors($values(0)),
            'min 1: none is refused, at the top and nested in a block',
        );
        $this->assertSame(
            ['items' => ['At most 2 blocks allowed.'], 'lists.' . self::A . '.quotes' => ['At most 2 blocks allowed.']],
            $errors($values(3)),
            'max 2',
        );
        $this->assertSame([], $this->errors('counted', $values(1)));
        $this->assertSame([], $this->errors('counted', $values(2)));
        $this->assertSame(['items' => ['Add at least 1 block.']], $this->errors('counted', ['items' => '', 'lists' => []]), 'an empty text counts none');
        $this->assertSame(['items' => ['Add at least 1 block.']], $this->errors('counted', []), 'nor does no value');
        $this->assertSame(['items' => ['At most 2 blocks allowed.']], $this->errors('counted', ['items' => (string) json_encode($quotes(3))]), 'JSON text is counted');
    }

    public function test_the_count_rule_counts_a_list_or_its_json_text(): void
    {
        $count = new \Preflow\Blocks\Rules\BlockCount(1, 2);
        $context = new ValidationContext('items', []);

        $this->assertSame('validation.blocks.min', $count->validate(null, $context)->key);
        $this->assertSame('validation.blocks.min', $count->validate('', $context)->key);
        $this->assertTrue($count->validate([['_block' => 'a']], $context));
        $this->assertTrue($count->validate('[{"_block":"a"},{"_block":"b"}]', $context));
        $this->assertSame('validation.blocks.max', $count->validate([['_block' => 'a'], ['_block' => 'b'], ['_block' => 'c']], $context)->key);
        $this->assertSame([], \Preflow\Blocks\Rules\BlockCount::of([]), 'no limits: no rule');
        $this->assertEquals([new \Preflow\Blocks\Rules\BlockCount(1, null)], \Preflow\Blocks\Rules\BlockCount::of(['min' => '1']));
    }

    /**
     * A valid section, with $values over it.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function section(string $uid, array $values = []): array
    {
        return array_replace(['_uid' => $uid, '_block' => 'section', 'title' => ['en' => 'Hello']], $values);
    }

    private function data(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function types(): TypeRegistry
    {
        return $this->app()->container()->get(TypeRegistry::class);
    }

    /**
     * The errors of a validated save of these values.
     *
     * @param array<string, mixed> $values
     * @param array<string, list<mixed>> $rules
     * @return array<string, list<string>>
     */
    private function errors(string $type, array $values, array $rules = []): array
    {
        try {
            $this->data()->validate(DynamicRecord::fromArray($this->types()->get($type), $values + ['uuid' => 'p1']), $rules);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
