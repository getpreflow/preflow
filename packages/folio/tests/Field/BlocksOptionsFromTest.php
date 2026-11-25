<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\BlockRegistry;
use Preflow\Data\Locales;
use Preflow\Folio\Field\BuildContext;
use Preflow\Folio\Field\FieldContext;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Field\Types\SelectFieldType;
use Preflow\Folio\Field\Types\StringFieldType;
use Preflow\Folio\Field\Types\TagsFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\PollBlock;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * A select inside a block taking its options from another field of the same block ("options_from",
 * 0.90): the block editor shows that block's values as the options, and a save accepts only one of
 * the values the same block submits. Each block instance uses its own values.
 */
final class BlocksOptionsFromTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $rendered = [];

    private function type(): BlocksFieldType
    {
        $test = $this;
        $engine = new class ($test) implements TemplateEngineInterface {
            public function __construct(private readonly BlocksOptionsFromTest $test) {}
            public function render(string $template, array $context = []): string
            {
                $this->test->rendered($template, $context);

                return '';
            }
            public function exists(string $template): bool { return true; }
            public function addFunction(TemplateFunctionDefinition $function): void {}
            public function addGlobal(string $name, mixed $value): void {}
            public function getTemplateExtension(): string { return 'twig'; }
            public function addNamespace(string $namespace, string $path): void {}
        };
        $fields = new FieldTypeRegistry();
        $fields->register(new StringFieldType());
        $fields->register(new TagsFieldType());
        $fields->register(new SelectFieldType());
        $blocks = new BlocksFieldType(new BlockRegistry([PollBlock::class]), $fields, 4, new ValidatorFactory(new RuleFactory()), $engine);
        $fields->register($blocks);

        return $blocks;
    }

    /** @param array<string, mixed> $context */
    public function rendered(string $template, array $context): void
    {
        $this->rendered[] = [$template, $context];
    }

    /**
     * The rendered fields of each card, by uid.
     *
     * @param list<array<string, mixed>> $value
     * @return array<string, list<string>>
     */
    private function cards(array $value, ?BlocksFieldType $type = null): array
    {
        $this->rendered = [];
        ($type ?? $this->type())->renderEditor(new FieldContext(name: 'body', value: $value, locale: 'en', locales: Locales::single('en')));
        $cards = [];
        foreach ($this->rendered as [$template, $context]) {
            if ($template === '@folio/admin/blocks/card.twig') {
                $cards[$context['uid']] = $context['fields'];
            }
        }

        return $cards;
    }

    public function test_the_editor_shows_each_blocks_own_source_values_as_options(): void
    {
        $cards = $this->cards([
            ['_uid' => self::A, '_block' => 'poll', 'answers' => ['Yes', 'No'], 'answer' => 'No', 'fallback' => 'Yes'],
            ['_uid' => self::B, '_block' => 'poll', 'answers' => ['Red', 'Blue'], 'answer' => ''],
        ]);

        [, $answerA, $fallbackA] = $cards[self::A];
        $this->assertStringContainsString('<option value="Yes">Yes</option>', $answerA);
        $this->assertStringContainsString('<option value="No" selected>No</option>', $answerA);
        $this->assertStringNotContainsString('Red', $answerA, 'another block\'s values are not this block\'s options');
        $this->assertStringContainsString('<input type="radio" name="body[' . self::A . '][fallback]" value="Yes" checked required>', $fallbackA, 'radio');
        $this->assertStringContainsString('value="No"', $fallbackA);

        [, $answerB, $fallbackB] = $cards[self::B];
        $this->assertStringContainsString('<option value="Red">Red</option>', $answerB);
        $this->assertStringContainsString('<option value="Blue">Blue</option>', $answerB);
        $this->assertStringNotContainsString('Yes', $answerB);
        $this->assertStringContainsString('value="Blue"', $fallbackB);
    }

    public function test_a_value_no_longer_an_option_is_kept_and_labelled(): void
    {
        [, $answer] = $this->cards([['_uid' => self::A, '_block' => 'poll', 'answers' => ['Yes'], 'answer' => 'Maybe']])[self::A];

        $this->assertStringContainsString('<option value="Maybe" selected>Maybe (no longer an option)</option>', $answer);
    }

    public function test_a_block_without_source_values_shows_a_note_naming_the_source(): void
    {
        $type = $this->type();
        $this->rendered = [];
        $type->renderNewCard('poll', 'body', Locales::single('en'), 'en', 0);
        $card = null;
        foreach ($this->rendered as [$template, $context]) {
            if ($template === '@folio/admin/blocks/card.twig') {
                $card = $context;
            }
        }

        $this->assertNotNull($card);
        $this->assertStringContainsString('Add values to Possible answers and save; they show up here as choices.', $card['fields'][1]);
        $this->assertStringContainsString('Add values to Possible answers and save', $card['fields'][2]);
    }

    public function test_a_save_accepts_only_a_value_the_same_block_submits(): void
    {
        $type = $this->type();
        $built = $type->buildValue([
            '_order' => [self::A, self::B],
            // The source is edited in the same save: "Maybe" is new, and counts.
            self::A => ['_block' => 'poll', 'answers' => 'Yes, No, Maybe', 'answer' => 'Maybe', 'fallback' => 'Yes'],
            self::B => ['_block' => 'poll', 'answers' => 'Red, Blue', 'answer' => 'Yes', 'fallback' => 'Red'],
        ], null, [], new BuildContext(Locales::single('en'), 'en', false));

        $errors = $type->validateBlocks($built, [], Locales::single('en'));

        $this->assertSame([self::B . '.answer' => ['Must be one of: Red, Blue.']], $errors, 'each block checks against its own values');
    }

    public function test_an_empty_choice_passes_unless_required(): void
    {
        $errors = $this->type()->validateBlocks([
            ['_uid' => self::A, '_block' => 'poll', 'answers' => ['Yes'], 'answer' => null, 'fallback' => null],
        ], [], Locales::single('en'));

        $this->assertSame([self::A . '.fallback'], array_keys($errors), 'the optional select passes empty; the required one does not');
    }

    public function test_no_source_values_names_the_source_field(): void
    {
        $errors = $this->type()->validateBlocks([
            ['_uid' => self::A, '_block' => 'poll', 'answers' => [], 'answer' => 'Yes', 'fallback' => 'Yes'],
        ], [], Locales::single('en'));

        $this->assertSame(['No choices yet: add values to Possible answers first.'], $errors[self::A . '.answer']);
    }
}
