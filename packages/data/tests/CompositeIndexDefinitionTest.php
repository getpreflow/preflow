<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\ModelSchema;
use Preflow\Data\Tests\Fixtures\ModelSchema\IndexedJob;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Data\TypeRegistry;

/** Composite indexes as a type declares them: #[Index] on a class, "indexes" in a model file. */
final class CompositeIndexDefinitionTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/Fixtures/ModelSchema/IndexedJob.php';
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/composite_index_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*.json') ?: []);
        rmdir($this->dir);
    }

    public function test_a_model_file_declares_composite_indexes(): void
    {
        $this->writeType(['state' => ['type' => 'string'], 'updated_at' => ['type' => 'date']], [['state', 'updated_at']]);

        $type = (new TypeRegistry($this->dir))->get('job');

        $this->assertSame([['state', 'updated_at']], $type->indexes);
        $this->assertSame([], $type->indexedFields(), 'the fields get no index of their own');
        $this->assertSame(['state', 'updated_at'], $type->indexedByOthers());
    }

    public function test_an_unknown_field_in_indexes_is_refused_naming_type_and_field(): void
    {
        $this->writeType(['state' => ['type' => 'string']], [['state', 'updated']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "job" has an index over "updated", which is not one of its fields.');
        (new TypeRegistry($this->dir))->get('job');
    }

    public function test_indexes_must_be_lists_of_field_names(): void
    {
        $this->writeType(['state' => ['type' => 'string']], ['state']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Type "job": "indexes" is a list of field lists');
        (new TypeRegistry($this->dir))->get('job');
    }

    public function test_a_definition_refuses_an_index_without_fields_or_over_the_id(): void
    {
        $fields = ['state' => new TypeFieldDefinition('state')];
        try {
            new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [[]]);
            $this->fail('an empty index was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Type "job" has an index without fields.', $e->getMessage());
        }

        $this->expectExceptionMessage('Type "job" has an index over "uuid", which is not one of its fields.');
        new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [['state', 'uuid']]);
    }

    public function test_an_index_over_a_translatable_field_is_refused(): void
    {
        $this->expectExceptionMessage('Type "job" has an index over "title", which is translatable: free text gets no index.');
        new TypeDefinition('job', 'jobs', 'sqlite', [
            'state' => new TypeFieldDefinition('state'),
            'title' => new TypeFieldDefinition('title', translatable: true),
        ], indexes: [['state', 'title']]);
    }

    public function test_an_index_past_mysqls_key_size_is_refused(): void
    {
        $fields = [
            'a' => new TypeFieldDefinition('a', length: 500),
            'b' => new TypeFieldDefinition('b', length: 300),
            'n' => new TypeFieldDefinition('n', 'number'),
        ];
        new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [['a', 'n'], ['b', 'n']]);

        $this->expectExceptionMessage('Type "job" has the index (a, b, n) of up to 3208 bytes; MySQL allows 3072: give its text fields a shorter "length".');
        new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [['a', 'b', 'n']]);
    }

    public function test_a_field_twice_in_one_index_is_refused(): void
    {
        $this->expectExceptionMessage('Type "job" has the index (state, state) with a field twice.');
        new TypeDefinition('job', 'jobs', 'sqlite', ['state' => new TypeFieldDefinition('state')], indexes: [['state', 'state']]);
    }

    public function test_two_indexes_with_one_name_are_refused(): void
    {
        $fields = [
            'state' => new TypeFieldDefinition('state', index: true),
            'a' => new TypeFieldDefinition('a'),
            'b' => new TypeFieldDefinition('b'),
            'a_b' => new TypeFieldDefinition('a_b'),
        ];
        try {
            new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [['state']]);
            $this->fail('an index the field declares already was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Type "job" has two indexes named idx_jobs_state ((state) and another): drop one, or rename a field.', $e->getMessage());
        }

        $this->expectExceptionMessage('Type "job" has two indexes named idx_jobs_a_b ((a_b) and another): drop one, or rename a field.');
        new TypeDefinition('job', 'jobs', 'sqlite', $fields, indexes: [['a', 'b'], ['a_b']]);
    }

    public function test_a_class_declares_them_with_the_index_attribute(): void
    {
        $this->assertSame([['state', 'updated_at'], ['company', 'state']], ModelSchema::of(IndexedJob::class)['indexes']);

        $type = (new TypeRegistry($this->dir, null, ['indexed_job' => IndexedJob::class]))->get('indexed_job');

        $this->assertSame([['state', 'updated_at'], ['company', 'state']], $type->indexes);
        $this->assertSame(['company'], $type->indexedFields());
    }

    public function test_the_admin_file_of_a_class_may_not_set_indexes(): void
    {
        file_put_contents($this->dir . '/indexed_job.json', (string) json_encode(['key' => 'indexed_job', 'label' => 'Jobs', 'indexes' => [['state']]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Model file indexed_job.json sets "indexes"');
        new TypeRegistry($this->dir, null, ['indexed_job' => IndexedJob::class]);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<mixed> $indexes
     */
    private function writeType(array $fields, array $indexes): void
    {
        file_put_contents($this->dir . '/job.json', (string) json_encode(
            ['key' => 'job', 'table' => 'jobs', 'storage' => 'sqlite', 'fields' => $fields, 'indexes' => $indexes],
        ));
    }
}
