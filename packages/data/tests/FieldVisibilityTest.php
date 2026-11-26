<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldVisibility;
use Preflow\Data\ShowWhenException;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/** show_when: a field shows only when a rule on another field of the same record holds. */
final class FieldVisibilityTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fv_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    /** @return array<string, array<string, mixed>> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/Fixtures/show-when-cases.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function holdsCases(): iterable
    {
        foreach (self::fixture()['holds'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function hiddenCases(): iterable
    {
        foreach (self::fixture()['hidden'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    public function test_the_fixture_has_at_least_thirty_cases(): void
    {
        $this->assertGreaterThanOrEqual(30, count(self::fixture()['holds']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('holdsCases')]
    public function test_holds(array $case): void
    {
        $rule = FieldVisibility::fromConfig($case['rule'], $case['fields'] ?? []);

        $this->assertSame($case['holds'], $rule->holds($case['values']));
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('hiddenCases')]
    public function test_hidden_fields(array $case): void
    {
        $this->assertSame($case['hidden'], FieldVisibility::hiddenFields($case['fields'], $case['values']));
    }

    public function test_hidden_fields_reads_type_field_definitions(): void
    {
        $fields = [
            'kind' => new TypeFieldDefinition('kind'),
            'button_url' => new TypeFieldDefinition('button_url', config: ['show_when' => ['field' => 'kind', '=' => 'link']]),
        ];

        $this->assertSame(['button_url'], FieldVisibility::hiddenFields($fields, ['kind' => 'video']));
        $this->assertSame([], FieldVisibility::hiddenFields($fields, ['kind' => 'link']));
    }

    public function test_rules_of_reads_definitions_and_config_arrays(): void
    {
        $rule = ['field' => 'kind', 'in' => ['link']];

        $this->assertSame(['b' => $rule], FieldVisibility::rulesOf([
            'a' => new TypeFieldDefinition('a'),
            'b' => new TypeFieldDefinition('b', config: ['show_when' => $rule]),
        ]));
        $this->assertSame(['y' => $rule], FieldVisibility::rulesOf(['x' => ['type' => 'string'], 'y' => ['show_when' => $rule]]));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function malformedRules(): iterable
    {
        yield 'no operator' => [['field' => 'kind'], 'show_when {"field":"kind"} has no operator; give one of =, !=, in, not_in, empty, filled (or all/any with a list of rules).'];
        yield 'two operators' => [['field' => 'kind', '=' => 'a', 'in' => ['b']], 'show_when {"field":"kind","=":"a","in":["b"]} has more than one operator; give one, or combine rules with all/any.'];
        yield 'unknown operator' => [['field' => 'kind', '>' => 3], 'show_when {"field":"kind",">":3} has the operator ">", which show_when does not know; use one of =, !=, in, not_in, empty, filled.'];
        yield 'no field' => [['=' => 'a'], 'show_when {"=":"a"} names no field; give "field" with the name of another field.'];
        yield 'null value' => [['field' => 'kind', '=' => null], 'show_when {"field":"kind","=":null} compares with no value; for no value use {"field": "kind", "empty": true}.'];
        yield 'empty text value' => [['field' => 'kind', '!=' => ''], 'show_when {"field":"kind","!=":""} compares with no value; for a value use {"field": "kind", "filled": true}.'];
        yield 'list for =' => [['field' => 'kind', '=' => ['a']], 'show_when {"field":"kind","=":["a"]} compares with a list; use "in" for a list of values, or give one value.'];
        yield 'in without a list' => [['field' => 'kind', 'in' => 'a'], 'show_when {"field":"kind","in":"a"} needs a list of values for "in", such as ["link", "video"].'];
        yield 'in with an empty list' => [['field' => 'kind', 'not_in' => []], 'show_when {"field":"kind","not_in":[]} needs a list of values for "not_in", such as ["link", "video"].'];
        yield 'empty false' => [['field' => 'kind', 'empty' => false], 'show_when {"field":"kind","empty":false} takes "empty": true only; for the opposite write {"field": "kind", "filled": true}.'];
        yield 'filled false' => [['field' => 'kind', 'filled' => false], 'show_when {"field":"kind","filled":false} takes "filled": true only; for the opposite write {"field": "kind", "empty": true}.'];
        yield 'all empty' => [['all' => []], 'show_when {"all":[]} needs a list of one or more rules for "all".'];
        yield 'all with more' => [['all' => [['field' => 'a', 'filled' => true]], 'field' => 'b'], 'show_when {"all":[{"field":"a","filled":true}],"field":"b"} combines rules with "all" and holds nothing else; move the other keys into a rule of the list.'];
    }

    /** @param array<string, mixed> $rule */
    #[DataProvider('malformedRules')]
    public function test_a_malformed_rule_is_refused(array $rule, string $message): void
    {
        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage($message);

        FieldVisibility::fromConfig($rule);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function notAnObject(): iterable
    {
        yield 'a text' => ['kind', 'Type "page": field "url": show_when is an object such as {"field": "kind", "=": "video"}; give one rule like it, or combine rules with {"all": [...]} or {"any": [...]}.'];
        yield 'true' => [true, 'Type "page": field "url": show_when is an object such as {"field": "kind", "=": "video"};'];
        yield 'a list of rules' => [[['field' => 'kind', '=' => 'link']], 'Type "page": field "url": show_when is an object such as {"field": "kind", "=": "video"};'];
    }

    #[DataProvider('notAnObject')]
    public function test_a_show_when_that_is_not_an_object_is_refused(mixed $showWhen, string $message): void
    {
        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage($message);

        FieldVisibility::assertRules('Type "page"', ['kind' => ['type' => 'string'], 'url' => ['show_when' => $showWhen]], static fn (string $field): bool => false);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function numberMismatches(): iterable
    {
        yield 'a word' => [['field' => 'count', '=' => 'many'], 'Type "page": field "url": show_when {"field":"count","=":"many"} compares the number field "count" with "many"; give a whole number, such as 3.'];
        yield 'a decimal text' => [['field' => 'count', '!=' => '2.5'], 'Type "page": field "url": show_when {"field":"count","!=":"2.5"} compares the number field "count" with "2.5"; give a whole number, such as 3.'];
        yield 'a decimal in a list' => [['field' => 'count', 'in' => [1, 2.5]], 'Type "page": field "url": show_when {"field":"count","in":[1,2.5]} compares the number field "count" with 2.5; give a whole number, such as 3.'];
    }

    /** @param array<string, mixed> $rule */
    #[DataProvider('numberMismatches')]
    public function test_a_number_field_refuses_a_value_that_is_not_a_whole_number(array $rule, string $message): void
    {
        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage($message);

        FieldVisibility::assertRules('Type "page"', ['count' => ['type' => 'number'], 'url' => ['show_when' => $rule]], static fn (string $field): bool => false);
    }

    public function test_a_number_field_takes_whole_numbers_as_numbers_or_text(): void
    {
        FieldVisibility::assertRules('Type "page"', [
            'count' => ['type' => 'number'],
            'a' => ['show_when' => ['field' => 'count', 'in' => [1, '2', -3, 4.0]]],
            'b' => ['show_when' => ['field' => 'count', '=' => true]],
            'c' => ['show_when' => ['field' => 'kind', '=' => 'many']],
            'kind' => ['type' => 'string'],
        ], static fn (string $field): bool => false);

        $this->addToAssertionCount(1);
    }

    public function test_hidden_fields_of_a_type_parses_its_rules_once_and_answers_as_hidden_fields(): void
    {
        $fields = [
            'kind' => new TypeFieldDefinition('kind'),
            'url' => new TypeFieldDefinition('url', config: ['show_when' => ['field' => 'kind', '=' => 'link']]),
        ];
        $type = new \Preflow\Data\TypeDefinition('page', 'page', 'default', $fields);

        foreach ([['kind' => 'video'], ['kind' => 'link'], []] as $values) {
            $this->assertSame(FieldVisibility::hiddenFields($fields, $values), FieldVisibility::hiddenFieldsOf($type, $values));
        }
        $this->assertSame([], FieldVisibility::hiddenFieldsOf(new \Preflow\Data\TypeDefinition('plain', 'plain', 'default', ['kind' => new TypeFieldDefinition('kind')]), []));
    }

    public function test_sources_lists_every_named_field_once(): void
    {
        $rule = FieldVisibility::fromConfig(['any' => [['field' => 'a', '=' => 'x'], ['all' => [['field' => 'b', 'filled' => true], ['field' => 'a', 'in' => ['y']]]]]]);

        $this->assertSame(['a', 'b'], $rule->sources());
    }

    public function test_validate_skips_rules_of_hidden_fields(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'button_url' => ['type' => 'string', 'validate' => ['required'], 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);

        // Hidden: its required rule drops out.
        $this->assertSame([], $this->errors(['kind' => 'video']));
        // Visible: it fails as before.
        $this->assertSame(['button_url'], array_keys($this->errors(['kind' => 'link'])));
        // Extra rules (Folio's) for a hidden field drop out too.
        $this->assertSame([], $this->errors(['kind' => 'video'], ['button_url' => ['required']]));
        $this->assertSame(['button_url'], array_keys($this->errors(['kind' => 'link'], ['button_url' => ['required']])));
    }

    public function test_a_hidden_bounded_field_keeps_its_length_check(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'code' => ['type' => 'string', 'index' => true, 'length' => 3, 'validate' => ['required'], 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);

        // The storage would refuse it, so the column length still applies; required does not.
        $this->assertSame(['code'], array_keys($this->errors(['kind' => 'video', 'code' => 'too long'])));
        $this->assertSame([], $this->errors(['kind' => 'video']));
    }

    public function test_a_hidden_translatable_field_is_not_validated_in_any_language(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'title' => ['type' => 'string', 'translatable' => true, 'validate' => ['required', 'regex:/^[a-z]+$/'], 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);
        $locales = new \Preflow\Data\Locales(['en', 'de'], 'en');

        $this->assertSame([], $this->errors(['kind' => 'video', 'title' => ['de' => 'Nicht OK']], [], $locales));
        $this->assertSame(['title', 'title.de'], array_keys($this->errors(['kind' => 'link', 'title' => ['de' => 'Nicht OK']], [], $locales)));
    }

    public function test_validate_without_show_when_is_unchanged(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'button_url' => ['type' => 'string', 'validate' => ['required']],
        ]);

        $this->assertSame(['button_url'], array_keys($this->errors(['kind' => 'video'])));
    }

    public function test_save_keeps_the_stored_value_of_a_hidden_field(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'button_url' => ['type' => 'string', 'validate' => ['url'], 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);
        [$registry, $dm] = $this->manager();
        $dm->saveType(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1', 'kind' => 'video', 'button_url' => 'not a url']));

        $this->assertSame('not a url', $dm->findType('page', 'p1')?->get('button_url'));
    }

    public function test_boot_refuses_unknown_source(): void
    {
        $this->writeType('page', [
            'button_url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "page": field "button_url": show_when names "kind", which is not a field of the type. Name a field of the same type.');

        new TypeRegistry($this->dir . '/models');
    }

    public function test_boot_refuses_self(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string', 'show_when' => ['field' => 'kind', 'filled' => true]],
        ]);

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "page": field "kind": show_when names the field itself. Name another field of the same type.');

        new TypeRegistry($this->dir . '/models');
    }

    public function test_boot_refuses_cycle(): void
    {
        $this->writeType('page', [
            'a' => ['type' => 'string', 'show_when' => ['field' => 'b', 'filled' => true]],
            'b' => ['type' => 'string', 'show_when' => ['any' => [['field' => 'kind', '=' => 'x'], ['field' => 'c', 'filled' => true]]]],
            'c' => ['type' => 'string', 'show_when' => ['field' => 'a', 'empty' => true]],
            'kind' => ['type' => 'string'],
        ]);

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "page": field "a": show_when names "b", which depends on "a" again (a → b → c → a). Break the cycle: let one of these fields show without depending on the others.');

        new TypeRegistry($this->dir . '/models');
    }

    public function test_boot_refuses_translatable_source(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string', 'translatable' => true],
            'button_url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "page": field "button_url": show_when names "kind", which is translatable. Name a field that is not translatable (one value in every language).');

        new TypeRegistry($this->dir . '/models');
    }

    public function test_boot_refuses_a_malformed_rule_naming_type_and_field(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'button_url' => ['type' => 'string', 'show_when' => ['field' => 'kind']],
        ]);

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "page": field "button_url": show_when {"field":"kind"} has no operator;');

        new TypeRegistry($this->dir . '/models');
    }

    public function test_validate_skips_rules_of_hidden_fields_of_a_model_class(): void
    {
        $registry = new TypeRegistry($this->dir . '/models', classes: ['fv_offer' => ShowWhenOffer::class]);
        $dm = new DataManager(
            drivers: ['default' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'default',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        $hidden = new ShowWhenOffer();
        $hidden->kind = 'video';
        $dm->save($hidden);
        $this->assertNotSame('', (string) $hidden->uuid);

        $visible = new ShowWhenOffer();
        $visible->kind = 'link';
        try {
            $dm->save($visible);
            $this->fail('A visible required field must fail.');
        } catch (ValidationException $e) {
            $this->assertSame(['url'], array_keys($e->errors()));
        }
    }

    public function test_boot_checks_a_model_class_field(): void
    {
        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "fv_teaser": field "url": show_when names "kind", which is not a field of the type. Name a field of the same type.');

        new TypeRegistry($this->dir . '/models', classes: ['fv_teaser' => ShowWhenTeaser::class]);
    }

    public function test_boot_check_is_kept_in_the_boot_manifest(): void
    {
        $this->writeType('page', [
            'kind' => ['type' => 'string'],
            'button_url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]);
        $manifest = \Preflow\Core\Boot\BootManifest::recording();
        new TypeRegistry($this->dir . '/models', manifest: $manifest);

        $this->assertArrayHasKey('data.show_when', $manifest->recorded()['catalogs']);
    }

    public function test_define_refuses_a_rule_naming_an_unknown_field(): void
    {
        $registry = new TypeRegistry($this->dir . '/models');

        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Type "ext_page": field "url": show_when names "kind", which is not a field of the type.');

        $registry->define(['key' => 'ext_page', 'table' => 'ext_page', 'fields' => [
            'url' => ['type' => 'string', 'show_when' => ['field' => 'kind', '=' => 'link']],
        ]]);
    }

    public function test_assert_rules_names_a_block_as_owner(): void
    {
        $this->expectException(ShowWhenException::class);
        $this->expectExceptionMessage('Block "hero": field "url": show_when names "kind", which is not a field of the block. Name a field of the same block.');

        FieldVisibility::assertRules('Block "hero"', ['url' => ['show_when' => ['field' => 'kind', '=' => 'link']]], static fn (string $field): bool => false, 'block');
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function writeType(string $key, array $fields): void
    {
        file_put_contents($this->dir . "/models/{$key}.json", json_encode([
            'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid', 'fields' => $fields,
        ]));
    }

    /** @return array{TypeRegistry, DataManager} */
    private function manager(?\Preflow\Data\Locales $locales = null): array
    {
        $registry = new TypeRegistry($this->dir . '/models', $locales);

        return [$registry, new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        )];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<mixed>> $rules
     * @return array<string, list<string>>
     */
    private function errors(array $data, array $rules = [], ?\Preflow\Data\Locales $locales = null): array
    {
        [$registry, $dm] = $this->manager($locales);
        try {
            $dm->validate(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1'] + $data), $rules);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
}

#[\Preflow\Data\Attributes\Entity(table: 'fv_teasers', type: 'fv_teaser')]
final class ShowWhenTeaser extends \Preflow\Data\Model
{
    #[\Preflow\Data\Attributes\Id]
    public string $uuid = '';

    #[\Preflow\Data\Attributes\Field(config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    public string $url = '';
}

#[\Preflow\Data\Attributes\Entity(table: 'fv_offers', type: 'fv_offer')]
final class ShowWhenOffer extends \Preflow\Data\Model
{
    #[\Preflow\Data\Attributes\Id]
    public ?string $uuid = null;

    #[\Preflow\Data\Attributes\Field]
    public string $kind = '';

    #[\Preflow\Data\Attributes\Field(config: ['show_when' => ['field' => 'kind', '=' => 'link']])]
    #[\Preflow\Validation\Attributes\Validate('required')]
    public ?string $url = null;
}
