<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\Driver\JsonFileDriver;
use Preflow\Data\DynamicRecord;
use Preflow\Data\RecordRules;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;
use Preflow\Validation\Message;
use Preflow\Validation\RuleFactory;
use Preflow\Validation\ValidationContext;
use Preflow\Validation\ValidationException;
use Preflow\Validation\ValidationRule;
use Preflow\Validation\ValidatorFactory;

/**
 * Rules a package adds for the records of some types (RecordRules, 0.92: preflow/blocks' page
 * layout rules): every validation of a typed record runs them after the field types' rules, and
 * those added for drafts also run in validateStorable(), with or without a validator factory.
 */
final class RecordRulesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rr_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0777, true);
        foreach (['page', 'note'] as $type) {
            file_put_contents($this->dir . '/models/' . $type . '.json', json_encode([
                'key' => $type, 'table' => $type, 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string']],
            ]));
        }
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    public function test_rules_apply_only_to_the_types_they_are_added_for(): void
    {
        [$types, $dm] = $this->manager();
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => $def->key === 'page' ? ['title' => ['required']] : []);

        $this->assertSame(['title' => ['This field is required.']], $this->errors($dm, $types, 'page', ['title' => '']));
        $this->assertSame([], $this->errors($dm, $types, 'note', ['title' => '']));
    }

    public function test_rules_given_for_a_field_replace_its_record_rules(): void
    {
        [$types, $dm] = $this->manager();
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => ['title' => ['required']]);

        $this->assertSame([], $this->errors($dm, $types, 'page', ['title' => ''], ['title' => []]));
    }

    public function test_rules_of_several_adds_are_merged_per_field(): void
    {
        $rules = new RecordRules();
        $rules->add(static fn (TypeDefinition $def): array => ['title' => ['required']]);
        $rules->add(static fn (TypeDefinition $def): array => ['title' => ['max:10'], 'slug' => ['required']], drafts: true);
        $def = new TypeDefinition('page', 'page', 'json', []);

        $this->assertSame(['title' => ['required', 'max:10'], 'slug' => ['required']], $rules->of($def));
        $this->assertSame(['title' => ['max:10'], 'slug' => ['required']], $rules->of($def, drafts: true), 'drafts: only those added for drafts');
        $this->assertSame([], (new RecordRules())->of($def));
    }

    public function test_validate_storable_runs_the_draft_rules_without_a_validator_factory(): void
    {
        $types = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(drivers: ['json' => new JsonFileDriver($this->dir . '/store')], defaultDriver: 'json', typeRegistry: $types);
        $seen = new \ArrayObject();
        $rule = new class ($seen) implements ValidationRule {
            public function __construct(private readonly \ArrayObject $seen) {}

            public function validate(mixed $value, ValidationContext $context): true|string|Message
            {
                $this->seen->append([$value, $context->field, $context->subject]);

                return $value === 'home' ? true : new Message('validation.required');
            }
        };
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => $def->key === 'page' ? ['slug' => [$rule]] : [], drafts: true);
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => ['title' => ['required']]);

        $record = DynamicRecord::fromArray($types->get('page'), ['uuid' => 'p1', 'title' => '', 'slug' => 'about']);
        try {
            $dm->validateStorable($record);
            $this->fail('the draft rule did not refuse');
        } catch (ValidationException $e) {
            $this->assertSame(['slug' => ['This field is required.']], $e->errors(), 'only the draft rule runs, keyed by its field');
        }
        $this->assertSame([['about', 'slug', $record]], $seen->getArrayCopy(), 'the rule gets the value, its field and the record');

        $dm->validateStorable(DynamicRecord::fromArray($types->get('page'), ['uuid' => 'p1', 'title' => '', 'slug' => 'home']));
        $dm->validateStorable(DynamicRecord::fromArray($types->get('note'), ['uuid' => 'n1', 'slug' => 'about']));
        $this->addToAssertionCount(1);
    }

    public function test_validate_storable_runs_draft_rules_in_the_validators_language_with_a_factory(): void
    {
        [$types, $dm] = $this->manager();
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => ['slug' => ['required']], drafts: true);

        try {
            $dm->validateStorable(DynamicRecord::fromArray($types->get('page'), ['uuid' => 'p1', 'slug' => '']));
            $this->fail('the draft rule did not refuse');
        } catch (ValidationException $e) {
            $this->assertSame(['slug' => ['This field is required.']], $e->errors(), 'a rule name works too');
        }
    }

    public function test_checks_run_with_the_given_rules_and_refuse_together_with_the_field_rules(): void
    {
        [$types, $dm] = $this->manager();
        $seen = new \ArrayObject();
        $dm->recordRules()->check(static function (DynamicRecord $record, array $given) use ($seen): array {
            $seen->append([$record->get('slug'), $given]);

            return $record->getType()->key === 'page' ? ['body.a1.text' => ['Missing text.']] : [];
        });
        $dm->recordRules()->add(static fn (TypeDefinition $def): array => ['title' => ['required']]);

        $this->assertCount(1, $dm->recordRules()->checks());
        $this->assertSame(['body.a1.text' => ['Missing text.'], 'title' => ['This field is required.']], $this->errors($dm, $types, 'page', ['title' => '', 'slug' => 'about']), 'one refusal with both');
        $this->assertSame(['body.a1.text' => ['Missing text.']], $this->errors($dm, $types, 'page', ['title' => 'T'], ['slug' => []]), 'a check alone refuses too');
        $this->assertSame([], $this->errors($dm, $types, 'note', ['title' => 'T']));
        $this->assertSame([['about', []], [null, ['slug' => []]], [null, []]], $seen->getArrayCopy(), 'each check gets the record and the rules the caller gave');
    }

    /** @return array{TypeRegistry, DataManager} */
    private function manager(): array
    {
        $types = new TypeRegistry($this->dir . '/models');
        $dm = new DataManager(
            drivers: ['json' => new JsonFileDriver($this->dir . '/store')],
            defaultDriver: 'json',
            typeRegistry: $types,
            validatorFactory: new ValidatorFactory(new RuleFactory()),
        );

        return [$types, $dm];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, list<mixed>> $rules
     * @return array<string, list<string>>
     */
    private function errors(DataManager $dm, TypeRegistry $types, string $type, array $data, array $rules = []): array
    {
        try {
            $dm->validate(DynamicRecord::fromArray($types->get($type), ['uuid' => 'r1'] + $data), $rules);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }
}
