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
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\OfferBlock;
use Preflow\Folio\Tests\Fixtures\Blocks\OfferGroupBlock;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidatorFactory;
use Preflow\View\TemplateEngineInterface;
use Preflow\View\TemplateFunctionDefinition;

/**
 * Conditional fields inside blocks (show_when, 0.91): the source is a field of the same block, so two
 * blocks of one type each decide on their own values. A hidden block field is not validated, keeps
 * its stored value on save (whatever is posted for it), and its card renders it hidden for fields.js.
 */
final class BlocksShowWhenTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbb';

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $rendered = [];

    /** @param list<class-string> $classes */
    private function type(array $classes = [OfferBlock::class, OfferGroupBlock::class]): BlocksFieldType
    {
        $test = $this;
        $engine = new class ($test) implements TemplateEngineInterface {
            public function __construct(private readonly BlocksShowWhenTest $test) {}
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
        $fields->register(new SelectFieldType());
        $blocks = new BlocksFieldType(new BlockRegistry($classes), $fields, 4, new ValidatorFactory(new RuleFactory()), $engine);
        $fields->register($blocks);

        return $blocks;
    }

    /** @param array<string, mixed> $context */
    public function rendered(string $template, array $context): void
    {
        $this->rendered[] = [$template, $context];
    }

    private static function ctx(): BuildContext
    {
        return new BuildContext(Locales::single('en'), 'en', false);
    }

    public function test_a_save_keeps_the_stored_value_of_a_hidden_block_field(): void
    {
        $stored = [['_uid' => self::A, '_block' => 'offer', 'kind' => 'link', 'button_url' => '/a']];

        // Switched to text: the disabled input does not post, and a posted value is ignored too.
        foreach ([[], ['button_url' => '/evil']] as $posted) {
            $built = $this->type()->buildValue([
                '_order' => [self::A, self::B],
                self::A => ['_block' => 'offer', 'kind' => 'text'] + $posted,
                self::B => ['_block' => 'offer', 'kind' => 'text', 'button_url' => '/new-and-hidden'],
            ], $stored, [], self::ctx());

            $this->assertSame(['_uid' => self::A, '_block' => 'offer', 'kind' => 'text', 'button_url' => '/a'], $built[0]);
            $this->assertSame(['_uid' => self::B, '_block' => 'offer', 'kind' => 'text'], $built[1], 'a new block keeps nothing it hides');
        }

        // Switched back: what is posted counts again.
        $built = $this->type()->buildValue(['_order' => [self::A], self::A => ['_block' => 'offer', 'kind' => 'link', 'button_url' => '/b']], $stored, [], self::ctx());
        $this->assertSame('/b', $built[0]['button_url']);
    }

    public function test_a_hidden_block_field_is_not_validated_each_block_deciding_on_its_own(): void
    {
        $errors = $this->type()->validateBlocks([
            ['_uid' => self::A, '_block' => 'offer', 'kind' => 'text', 'button_url' => ''],
            ['_uid' => self::B, '_block' => 'offer', 'kind' => 'link', 'button_url' => ''],
        ], [], Locales::single('en'));

        $this->assertSame([self::B . '.button_url'], array_keys($errors), 'required applies only where the field shows');
    }

    public function test_a_hidden_nested_blocks_field_is_not_validated(): void
    {
        $type = $this->type();
        $this->assertSame([], $type->validateBlocks([['_uid' => self::A, '_block' => 'offer_group', 'layout' => 'none', 'offers' => []]], [], Locales::single('en')));
        $this->assertSame([self::A . '.offers'], array_keys($type->validateBlocks([['_uid' => self::A, '_block' => 'offer_group', 'layout' => 'list', 'offers' => []]], [], Locales::single('en'))));
    }

    public function test_the_card_renders_a_hidden_field_hidden_with_its_rule_for_the_script(): void
    {
        $this->rendered = [];
        $this->type()->renderEditor(new FieldContext(name: 'body', value: [
            ['_uid' => self::A, '_block' => 'offer', 'kind' => 'text', 'button_url' => '/a'],
            ['_uid' => self::B, '_block' => 'offer', 'kind' => 'link', 'button_url' => '/b'],
        ], locale: 'en', locales: Locales::single('en')));
        $cards = [];
        foreach ($this->rendered as [$template, $context]) {
            if ($template === '@folio/admin/blocks/card.twig') {
                $cards[$context['uid']] = $context['fields'];
            }
        }

        [$kindA, $urlA] = $cards[self::A];
        $this->assertStringNotContainsString('data-folio-show-when', $kindA, 'a field without show_when is not wrapped');
        $config = htmlspecialchars('{"rule":{"field":"kind","=":"link"},"types":{"kind":"select"},"values":{"kind":"text"},"prefix":"body[' . self::A . ']"}', ENT_QUOTES, 'UTF-8');
        $this->assertStringStartsWith('<div class="folio-show-when" data-folio-show-when="' . $config . '" hidden aria-hidden="true">', $urlA);
        $this->assertStringContainsString('value="/a"', $urlA, 'the stored value is in the hidden input, ready when it shows');
        [, $urlB] = $cards[self::B];
        $this->assertStringContainsString('data-folio-show-when=', $urlB);
        $this->assertStringNotContainsString(' hidden', $urlB);
    }

    public function test_a_hidden_block_text_is_left_out_of_the_translatable_texts(): void
    {
        $locales = new Locales(['en', 'de'], 'en');
        $texts = $this->type()->translatableTexts([
            ['_uid' => self::A, '_block' => 'offer', 'kind' => 'text', 'button_label' => ['en' => 'Hidden']],
            ['_uid' => self::B, '_block' => 'offer', 'kind' => 'link', 'button_label' => ['en' => 'Shown']],
        ], [], $locales);

        $this->assertSame([self::B . '.button_label'], array_map(static fn ($text): string => $text->path, $texts));
    }

    public function test_the_script_comes_only_with_blocks_that_have_show_when(): void
    {
        $this->assertContains('show-when-core.js', $this->type()->assets());
        $this->assertNotContains('show-when-core.js', $this->type([LinkBlock::class])->assets());
    }
}
