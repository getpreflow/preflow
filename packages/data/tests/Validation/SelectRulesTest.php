<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Validation\OptionsFrom;
use Preflow\Data\Validation\SelectRules;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\Rules\InList;
use Preflow\Validation\Rules\WhenFilled;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * A select's value must be one of its options (0.92: checked by the core on every validated write,
 * not only by Folio's form): "options" give an in: rule of their values, "options_from" the values
 * of another field in the same data (the record, or the block), with the messages Folio showed.
 */
final class SelectRulesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/selr_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/question.json', json_encode([
            'key' => 'question', 'table' => 'question', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'size' => ['type' => 'select', 'options' => ['s' => 'Small', 'm' => 'Medium']],
                'colour' => ['type' => 'select', 'options' => ['Red', 'Blue']],
                'answers' => ['type' => 'tags', 'label' => 'Possible answers'],
                'answer' => ['type' => 'select', 'options_from' => 'answers'],
                'lines' => ['type' => 'text'],
                'line' => ['type' => 'select', 'options_from' => 'lines'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_options_give_an_in_rule_of_their_values(): void
    {
        $this->assertEquals([new WhenFilled(new InList(['s', 'm']))], SelectRules::of(new TypeFieldDefinition('size', 'select', config: ['options' => ['s' => 'Small', 'm' => 'Medium']]), []));
        $this->assertEquals([new WhenFilled(new InList(['Red', 'Blue']))], SelectRules::of(new TypeFieldDefinition('colour', 'select', config: ['options' => ['Red', 'Blue']]), []), 'a plain list: each value its own label');
        $this->assertEquals([new WhenFilled(new InList(['a']))], SelectRules::of(new TypeFieldDefinition('card', 'select', config: ['options' => ['a' => ['label' => 'A', 'help' => 'The first']]]), []), 'described options');
        $this->assertSame([], SelectRules::of(new TypeFieldDefinition('empty', 'select'), []), 'no options: no rule');
        $this->assertSame([], SelectRules::of(new TypeFieldDefinition('title', 'string', config: ['options' => ['a']]), []), 'not a select');
    }

    /** @return iterable<string, array{array<array-key, mixed>, string, string}> options, a value they offer, one they do not */
    public static function optionShapes(): iterable
    {
        yield 'map of text labels' => [['s' => 'Small', 'm' => 'Medium'], 's', 'Small'];
        yield 'map of labels per language' => [['s' => ['en' => 'Small', 'de' => 'Klein']], 's', 'Klein'];
        yield 'map of described options' => [['301' => ['label' => 'Moved', 'help' => 'For good.']], '301', 'Moved'];
        yield 'map with numeric keys' => [[1 => 'One', 5 => 'Five'], '5', 'Five'];
        yield 'plain list' => [['Red', 'Blue'], 'Blue', 'Green'];
        yield 'list of described options' => [[['label' => 'Red', 'help' => 'Warm.']], 'Red', 'Warm.'];
        yield 'list of labels per language' => [[['en' => 'Red', 'de' => 'Rot'], ['en' => 'Blue', 'de' => 'Blau']], 'Blue', 'Rot'];
        yield 'list of described options with labels per language' => [[['label' => ['en' => 'Red', 'de' => 'Rot'], 'help' => 'Warm.']], 'Red', 'Rot'];
    }

    /** @param array<array-key, mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('optionShapes')]
    public function test_every_option_shape_folio_takes_gets_a_rule(array $options, string $offered, string $other): void
    {
        $rules = SelectRules::of(new TypeFieldDefinition('choice', 'select', config: ['options' => $options]), []);
        $check = static fn (string $value): array => (new ValidatorFactory(new RuleFactory()))->make(['choice' => $rules], ['choice' => $value])->validate()->errors();

        $this->assertNotSame([], $rules, 'no select is looser than in 0.91');
        $this->assertSame([], $check($offered));
        $this->assertCount(1, $check($other)['choice'] ?? []);
    }

    public function test_a_list_of_labels_per_language_offers_the_renders_language(): void
    {
        // Folio's form offers (and 0.91 checked) each label in the admin's language as the value.
        $field = new TypeFieldDefinition('colour', 'select', config: ['options' => [['en' => 'Red', 'de' => 'Rot'], ['en' => 'Blue', 'de' => 'Blau']]]);
        $de = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->admin('de'), static fn (): array => SelectRules::of($field, []));

        $this->assertEquals([new WhenFilled(new InList(['Rot', 'Blau']))], $de);
        $this->assertEquals([new WhenFilled(new InList(['Red', 'Blue']))], SelectRules::of($field, []), 'outside every render: English');
    }

    public function test_the_source_is_named_by_the_field_labels_when_given(): void
    {
        $labels = new class implements \Preflow\Data\FieldLabels {
            public function fieldLabel(TypeFieldDefinition $field, string $locale, ?\Preflow\Data\TypeDefinition $type = null): string
            {
                return $field->name . '@' . $locale . ($type === null ? ' (block)' : ' (' . $type->key . ')');
            }
        };
        $answers = new TypeFieldDefinition('answers', 'tags', label: 'Possible answers', labels: ['en' => 'Possible answers', 'de' => 'Mögliche Antworten']);
        $select = new TypeFieldDefinition('answer', 'select', config: ['options_from' => 'answers']);
        $type = new \Preflow\Data\TypeDefinition('poll', 'poll', 'json', ['answers' => $answers, 'answer' => $select]);

        $this->assertEquals([new OptionsFrom('answers', 'answers@en (poll)')], SelectRules::of($select, $type->fields, null, $labels, $type));
        $this->assertEquals([new OptionsFrom('answers', 'answers@en (block)')], SelectRules::of($select, $type->fields, null, $labels));
        $de = \Preflow\Core\Context\Contexts::run(\Preflow\Testing\ContextFactory::forTest()->admin('de'), static fn (): array => SelectRules::of($select, $type->fields, null, null, $type));
        $this->assertEquals([new OptionsFrom('answers', 'Mögliche Antworten')], $de, 'without FieldLabels: a record field\'s label in the render\'s language');
    }

    public function test_options_from_reads_the_source_from_the_same_data(): void
    {
        $siblings = ['answers' => new TypeFieldDefinition('answers', 'tags', label: 'Possible answers')];
        $rules = SelectRules::of(new TypeFieldDefinition('answer', 'select', config: ['options_from' => 'answers']), $siblings);

        $this->assertEquals([new OptionsFrom('answers', 'Possible answers')], $rules);
        $this->assertEquals(
            [new OptionsFrom('answers', 'Answers')],
            SelectRules::of(new TypeFieldDefinition('answer', 'select', config: ['options_from' => 'answers']), ['answers' => new TypeFieldDefinition('answers', 'tags')]),
            'without a label: the humanized name',
        );
        $kinds = new FieldKinds();
        $kinds->list('tags');
        $this->assertEquals([new OptionsFrom('answers', 'Possible answers', list: true)], SelectRules::of(new TypeFieldDefinition('answer', 'select', config: ['options_from' => 'answers']), $siblings, $kinds), 'a list source: a comma text gives its entries');
    }

    public function test_a_record_value_outside_the_options_is_refused_without_folio(): void
    {
        $this->assertSame(['size' => ['Must be one of: s, m.']], $this->errors(['size' => 'xl']));
        $this->assertSame(['colour' => ['Must be one of: Red, Blue.']], $this->errors(['colour' => 'Green']));
        $this->assertSame([], $this->errors(['size' => 's', 'colour' => 'Red']));
        $this->assertSame([], $this->errors(['size' => null, 'colour' => '']), 'empty passes unless required');
    }

    public function test_options_from_on_a_record(): void
    {
        $this->assertSame([], $this->errors(['answers' => ['Pizza', 'Soup'], 'answer' => 'Soup']));
        $this->assertSame(['answer' => ['Must be one of: Pizza.']], $this->errors(['answers' => ['Pizza'], 'answer' => 'Soup']));
        $this->assertSame([], $this->errors(['answers' => '["Pizza","Soup"]', 'answer' => 'Soup']), 'JSON text of a list');
        $this->assertSame(['answer' => ['No choices yet: add values to Possible answers first.']], $this->errors(['answers' => [], 'answer' => 'Soup']), 'no source values: the message names the source');
        $this->assertSame(['line' => ['Must be one of: Pizza, Soup.']], $this->errors(['lines' => 'Pizza, Soup', 'line' => 'Pizza']), 'a text source is one value');
    }

    public function test_options_from_on_a_block(): void
    {
        // A block's fields are the siblings, the block's values the data.
        $block = [
            'answers' => new TypeFieldDefinition('answers', 'tags', label: 'Possible answers'),
            'answer' => new TypeFieldDefinition('answer', 'select', config: ['options_from' => 'answers']),
        ];
        $validator = (new ValidatorFactory(new RuleFactory()))->make(['answer' => SelectRules::of($block['answer'], $block)], ['answers' => ['Red', 'Blue'], 'answer' => 'Yes']);

        $this->assertSame(['answer' => ['Must be one of: Red, Blue.']], $validator->validate()->errors());
    }

    public function test_the_values_of_a_source(): void
    {
        $this->assertSame(['a', 'b'], OptionsFrom::values(['a', ' b ', '', 'a']));
        $this->assertSame(['a', 'b'], OptionsFrom::values('["a","b"]'));
        $this->assertSame(['a, b'], OptionsFrom::values('a, b'));
        $this->assertSame(['a', 'b'], OptionsFrom::values('a, b', list: true));
        $this->assertSame([], OptionsFrom::values(null));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, list<string>>
     */
    private function errors(array $values): array
    {
        $types = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(['json' => new JsonFileDriver($this->dir . '/store')], 'json', $types, new ValidatorFactory(new RuleFactory()));
        try {
            $dm->validate(DynamicRecord::fromArray($types->get('question'), ['uuid' => 'q1'] + $values));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
