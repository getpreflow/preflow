<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Field;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;
use Preflow\Blocks\BlockRegistry;
use Preflow\Folio\Content\RecordValidator;
use Preflow\Folio\Field\FieldRules;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;
use Preflow\Folio\Field\Types\BlocksFieldType;
use Preflow\Folio\Tests\Fixtures\Blocks\LinkBlock;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/** FieldType::rules() may return ValidationRule objects that are not Stringable (0.66): no cast, no 500. */
final class FieldRuleObjectsTest extends TestCase
{
    public function test_merge_keeps_objects_and_drops_repeated_names(): void
    {
        $rule = new NoJavascriptLink();
        $other = new NoJavascriptLink();

        $merged = FieldRules::merge(['required', 'url'], ['url', $rule, $rule, $other]);

        $this->assertSame(['required', 'url', $rule, $other], $merged);
    }

    public function test_a_stringable_object_is_kept_once_against_its_name(): void
    {
        $required = new class implements ValidationRule, \Stringable {
            public function validate(mixed $value, ValidationContext $context): true|string|Message
            {
                return true;
            }

            public function __toString(): string
            {
                return 'required';
            }
        };

        $this->assertSame(['required'], FieldRules::merge(['required'], [$required]));
        $this->assertSame([$required], FieldRules::merge([$required], ['required']));
    }

    public function test_the_record_validator_runs_a_rule_object_of_a_field_type(): void
    {
        $dir = sys_get_temp_dir() . '/fro_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json',
            'fields' => ['website' => ['type' => 'link', 'validate' => ['required']]],
        ]));
        try {
            $registry = new TypeRegistry($dir, Locales::single('en'));
            $dm = new DataManager(
                drivers: ['json' => new JsonFileDriver($dir . '/store')],
                defaultDriver: 'json',
                typeRegistry: $registry,
                validatorFactory: new ValidatorFactory(new RuleFactory()),
            );
            $fieldTypes = new FieldTypeRegistry();
            $fieldTypes->register(new LinkFieldType());
            $validator = new RecordValidator($fieldTypes, $dm);
            $def = $registry->get('page');

            $validator->validate(DynamicRecord::fromArray($def, ['uuid' => 'ok', 'website' => 'https://example.com']));
            try {
                $validator->validate(DynamicRecord::fromArray($def, ['uuid' => 'bad', 'website' => 'javascript:alert(1)']));
                $this->fail('expected the rule object to refuse the link');
            } catch (ValidationException $e) {
                $this->assertSame(['website' => ['Not a safe link.']], $e->errors());
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function test_blocks_run_a_rule_object_of_a_field_type(): void
    {
        $fields = new FieldTypeRegistry();
        $fields->register(new LinkFieldType('string'));
        $blocks = new BlocksFieldType(new BlockRegistry([LinkBlock::class]), $fields, 4, new ValidatorFactory(new RuleFactory()));
        $fields->register($blocks);
        $uid = 'aaaaaaaaaaaaaaaa';

        $errors = $blocks->validateBlocks([['_uid' => $uid, '_block' => 'link', 'label' => ['en' => 'x'], 'url' => 'javascript:alert(1)']], [], Locales::single('en'));

        $this->assertSame(['Not a safe link.'], $errors[$uid . '.url'] ?? null);
    }
}

/** A rule object that is not Stringable. */
final class NoJavascriptLink implements ValidationRule
{
    public function validate(mixed $value, ValidationContext $context): true|string|Message
    {
        return is_string($value) && preg_match('/^\s*javascript:/i', $value) === 1 ? 'Not a safe link.' : true;
    }
}

final class LinkFieldType extends AbstractScalarFieldType
{
    public function __construct(private readonly string $key = 'link')
    {
        parent::__construct();
    }

    public function key(): string
    {
        return $this->key;
    }

    protected function inputType(): string
    {
        return 'url';
    }

    public function rules(array $config): array
    {
        return ['nullable', new NoJavascriptLink()];
    }
}
