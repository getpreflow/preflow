<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\ModelSchema;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Tests\Fixtures\ModelSchema\Applicant;

/** "metaField" beside "labelField": read from a model file, written by #[Relation], and the same both ways. */
final class RelationMetaFieldTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') as $fixture) {
            require_once $fixture;
        }
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_relation_meta_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.json') ?: []);
        rmdir($this->dir);
    }

    public function test_a_model_file_names_the_meta_field(): void
    {
        file_put_contents($this->dir . '/application.json', (string) json_encode([
            'key' => 'application',
            'table' => 'application',
            'fields' => [
                'candidate' => ['type' => 'relation', 'relation' => ['to' => 'candidate', 'labelField' => 'name', 'metaField' => 'email']],
                'company' => ['type' => 'relation', 'relation' => ['to' => 'company', 'metaField' => '']],
            ],
        ]));
        $relations = (new DataManager([], 'default', new TypeRegistry($this->dir)))->relationsOf('application');

        $this->assertSame(['candidate' => 'email', 'company' => null], array_column(
            array_map(static fn ($r): array => [$r->field, $r->metaField], $relations),
            1,
            0,
        ), 'an empty name is none');
    }

    public function test_the_attribute_writes_it_to_the_schema(): void
    {
        $schema = ModelSchema::of(Applicant::class);

        $this->assertSame(['to' => 'candidate', 'onDelete' => 'restrict', 'labelField' => 'name', 'metaField' => 'email'], $schema['fields']['candidate']['relation']);
        $this->assertSame(['to' => 'company', 'onDelete' => 'restrict'], $schema['fields']['company']['relation'], 'none: no key');
    }

    public function test_a_class_and_its_schema_give_the_same_relation(): void
    {
        file_put_contents($this->dir . '/as_file.json', (string) json_encode(['key' => 'as_file', 'table' => 'as_file'] + ModelSchema::of(Applicant::class)));
        $registry = new TypeRegistry($this->dir, null, ['applicant' => Applicant::class]);
        $dm = new DataManager([], 'default', $registry);

        $fromClass = $dm->relationsOf('applicant')[0];
        $fromFile = $dm->relationsOf('as_file')[0];

        $this->assertSame('email', $fromClass->metaField);
        $this->assertSame('name', $fromClass->labelField);
        $this->assertSame([$fromClass->metaField, $fromClass->labelField], [$fromFile->metaField, $fromFile->labelField]);
    }
}
