<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\FieldKinds;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidatorFactory;

/**
 * A field type's own rules (FieldKinds::rules(), 0.91): a package that defines a field type
 * (preflow/fields' link) registers its checks once, and every write of a typed record runs them
 * (Folio, the REST API, imports, seeds), next to the field's own "validate".
 */
final class FieldTypeRulesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/ftr_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        file_put_contents($this->dir . '/models/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'kind' => ['type' => 'string'],
                'contact' => ['type' => 'phone_number', 'validate' => ['required'], 'country' => 'ch'],
                'hidden_contact' => ['type' => 'phone_number', 'show_when' => ['field' => 'kind', '=' => 'contact']],
                'title' => ['type' => 'string'],
            ],
        ]));
        file_put_contents($this->dir . '/models/product.json', json_encode([
            'key' => 'product', 'table' => 'product', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => [
                'name' => ['type' => 'string'],
                'price' => ['type' => 'number'],
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

    public function test_a_type_without_rules_has_none(): void
    {
        $this->assertSame([], (new FieldKinds())->rulesOf(new TypeFieldDefinition('title', 'string')));
    }

    public function test_the_rules_get_the_fields_config(): void
    {
        $kinds = new FieldKinds();
        $kinds->rules('phone_number', static fn (array $config): array => $config['country'] === 'ch' ? ['phone'] : []);

        $this->assertSame(['phone'], $kinds->rulesOf(new TypeFieldDefinition('contact', 'phone_number', config: ['country' => 'ch'])));
        $this->assertSame([], $kinds->rulesOf(new TypeFieldDefinition('contact', 'phone_number', config: ['country' => 'de'])));
    }

    public function test_a_save_runs_the_types_rules_with_the_fields_own(): void
    {
        [$registry, $dm] = $this->manager();

        $this->assertSame(['contact' => ['Must be a phone number: digits, optionally with a leading + and spaces, e.g. +41 44 123 45 67.']], $this->errors($dm, $registry, ['contact' => 'call me']));
        $this->assertSame(['contact' => ['This field is required.']], $this->errors($dm, $registry, ['contact' => '']), 'the field\'s own rules still apply');
        $this->assertSame([], $this->errors($dm, $registry, ['contact' => '+41 44 123 45 67']));

        $this->expectException(ValidationException::class);
        $dm->saveType(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1', 'contact' => 'call me']));
    }

    public function test_a_field_its_show_when_hides_is_not_checked_by_its_types_rules(): void
    {
        [$registry, $dm] = $this->manager();

        $this->assertSame([], $this->errors($dm, $registry, ['contact' => '+41 44 123 45 67', 'kind' => 'text', 'hidden_contact' => 'call me']));
        $this->assertSame(['hidden_contact'], array_keys($this->errors($dm, $registry, ['contact' => '+41 44 123 45 67', 'kind' => 'contact', 'hidden_contact' => 'call me'])));
    }

    public function test_rules_given_for_a_field_replace_its_types_rules(): void
    {
        // A caller that builds the whole rule list of a field (Folio's RecordValidator, its read-only
        // fields' empty list) replaces the type's rules, as it replaces the field's own "validate".
        [$registry, $dm] = $this->manager();

        $this->assertSame([], $this->errors($dm, $registry, ['contact' => 'call me'], ['contact' => []]));
    }

    public function test_number_field_refuses_text_without_folio(): void
    {
        [$registry, $dm] = $this->manager();

        $this->assertSame(['price' => ['Must be a number.']], $this->numberErrors($dm, $registry, 'abc'));
        foreach (['3.5', '-7', '', null, 12, 4.25] as $ok) {
            $this->assertSame([], $this->numberErrors($dm, $registry, $ok), var_export($ok, true));
        }

        $this->expectException(ValidationException::class);
        $dm->saveType(DynamicRecord::fromArray($registry->get('product'), ['uuid' => 'x1', 'price' => 'abc']));
    }

    public function test_stored_number_text_reads_null_and_is_kept(): void
    {
        [$registry, $dm] = $this->manager();
        $dm->driver('json')->save('product', 'old', ['uuid' => 'old', 'name' => 'Old', 'price' => 'abc']);

        $record = $dm->findType('product', 'old') ?? self::fail('no record');
        $field = $registry->get('product')->fields['price'];
        $this->assertNull($dm->fieldKinds()->valueOf($field)->toOutput($record->get('price'), $field), 'reads as no number');
        $this->assertSame('abc', $dm->driver('json')->findOne('product', 'old', 'uuid')['price'], 'the stored text is kept by a read');
    }

    /** @return array<string, list<string>> */
    private function numberErrors(DataManager $dm, TypeRegistry $registry, mixed $price): array
    {
        try {
            $dm->saveType(DynamicRecord::fromArray($registry->get('product'), ['uuid' => 'p1', 'price' => $price]));
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /** @return array{TypeRegistry, DataManager} */
    private function manager(): array
    {
        $registry = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $registry,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );
        $dm->fieldKinds()->rules('phone_number', static fn (array $config): array => ['phone']);

        return [$registry, $dm];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<mixed>> $rules
     * @return array<string, list<string>>
     */
    private function errors(DataManager $dm, TypeRegistry $registry, array $data, array $rules = []): array
    {
        try {
            $dm->validate(DynamicRecord::fromArray($registry->get('page'), ['uuid' => 'p1'] + $data), $rules);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
