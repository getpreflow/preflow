<?php

declare(strict_types=1);

namespace Preflow\Data\Tests\Values;

use PHPUnit\Framework\TestCase;
use Preflow\Data\FieldKinds;
use Preflow\Data\Html\HtmlCleaner;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\Values\CoreValues;
use Preflow\Data\Values\StoredCleaning;

/**
 * What every typed write cleans before the driver (0.93): each field whose value cleans on write
 * (CleansOnWrite: rich text), every locale of a translatable one, then each registered system key.
 */
final class StoredCleaningTest extends TestCase
{
    private static function kinds(): FieldKinds
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds, static fn (): HtmlCleaner => new class implements HtmlCleaner {
            public function clean(string $html): string
            {
                return strip_tags($html, '<p>');
            }
        });

        return $kinds;
    }

    private static function page(): TypeDefinition
    {
        return new TypeDefinition('page', 'pages', 'default', [
            'title' => new TypeFieldDefinition('title'),
            'body' => new TypeFieldDefinition('body', 'richtext'),
            'intro' => new TypeFieldDefinition('intro', 'richtext', translatable: true),
        ]);
    }

    public function test_cleans_rich_text_fields_and_every_locale(): void
    {
        $cleaning = new StoredCleaning(self::kinds());

        $this->assertSame(
            ['title' => '<b>kept</b>', 'body' => '<p>x</p>', 'intro' => ['en' => '<p>a</p>', 'de' => '<p>b</p>', 'fr' => null], 'other' => '<b>kept</b>'],
            $cleaning->apply(self::page(), ['title' => '<b>kept</b>', 'body' => '<p><b>x</b></p>', 'intro' => ['en' => '<p><i>a</i></p>', 'de' => '<p>b</p>', 'fr' => null], 'other' => '<b>kept</b>']),
        );
    }

    public function test_a_translatable_map_read_back_as_json_text_is_cleaned_per_locale(): void
    {
        $cleaning = new StoredCleaning(self::kinds());

        $this->assertSame(
            ['intro' => ['en' => '<p>a</p>', 'de' => '<p>b</p>'], 'body' => '{"en":"x"}'],
            $cleaning->apply(self::page(), ['intro' => '{"en":"<p><i>a</i></p>","de":"<p>b</p>"}', 'body' => '{"en":"<b>x</b>"}']),
            'a SQL driver gives the map as JSON text; a field that is not translatable is HTML text',
        );
    }

    public function test_a_field_the_type_transforms_itself_is_left_as_stored(): void
    {
        $def = new TypeDefinition('page', 'pages', 'default', ['body' => new TypeFieldDefinition('body', 'richtext')], transformers: ['body' => new class implements \Preflow\Data\FieldTransformer {
            public function toStorage(mixed $value): mixed
            {
                return base64_encode((string) $value);
            }

            public function fromStorage(mixed $value): mixed
            {
                return base64_decode((string) $value);
            }
        }]);

        $this->assertSame(['body' => '<b>x</b>'], (new StoredCleaning(self::kinds()))->apply($def, ['body' => '<b>x</b>']), 'its storage form is the transformer\'s, as storageValues() leaves it');
    }

    public function test_fields_cleans_any_values(): void
    {
        $cleaning = new StoredCleaning(self::kinds());
        $fields = ['text' => new TypeFieldDefinition('text', 'richtext'), 'n' => new TypeFieldDefinition('n', 'number')];

        $this->assertSame(['text' => '<p>x</p>', 'n' => 3], $cleaning->fields(['text' => '<p><b>x</b></p>', 'n' => 3], $fields));
        $this->assertSame(['n' => 3], $cleaning->fields(['n' => 3], $fields), 'a field not given stays absent');
    }

    public function test_system_key_step_runs_only_when_the_key_is_there(): void
    {
        $cleaning = new StoredCleaning(self::kinds());
        $calls = [];
        $cleaning->systemKey('_areas', static function (mixed $value, TypeDefinition $def) use (&$calls): mixed {
            $calls[] = $def->key;

            return ['cleaned' => $value];
        });

        $this->assertSame(['title' => 'A'], $cleaning->apply(self::page(), ['title' => 'A']));
        $this->assertSame([], $calls, 'no "_areas": the step does not run');

        $this->assertSame(['title' => 'A', '_areas' => ['cleaned' => ['main' => []]]], $cleaning->apply(self::page(), ['title' => 'A', '_areas' => ['main' => []]]));
        $this->assertSame(['page'], $calls);

        $this->assertSame(['_areas' => ['cleaned' => null]], $cleaning->apply(self::page(), ['_areas' => null]), 'a key given as null is there');
    }

    public function test_without_a_rich_text_value_nothing_is_cleaned(): void
    {
        $kinds = new FieldKinds();
        CoreValues::register($kinds);

        $this->assertFalse($kinds->hasValue('richtext'), 'no cleaner given: no richtext value');
        $this->assertSame(['body' => '<script>x</script>'], (new StoredCleaning($kinds))->apply(self::page(), ['body' => '<script>x</script>']));
    }
}
