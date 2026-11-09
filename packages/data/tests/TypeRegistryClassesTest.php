<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\TypeRegistry;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;

final class TypeRegistryClassesTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    public static function setUpBeforeClass(): void
    {
        // The monorepo's root autoloader does not map this package's test namespace.
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') as $fixture) {
            require_once $fixture;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir . '/*.json'));
            rmdir($dir);
        }
    }

    private function emptyDir(): string
    {
        $dir = sys_get_temp_dir() . '/preflow_registry_classes_' . bin2hex(random_bytes(4));
        mkdir($dir, 0755, true);

        return $this->dirs[] = $dir;
    }

    private function dirWith(string $file, array $schema): string
    {
        $dir = $this->emptyDir();
        file_put_contents($dir . '/' . $file, json_encode($schema));

        return $dir;
    }

    public function test_a_class_is_a_type_without_a_file(): void
    {
        $registry = new TypeRegistry($this->emptyDir(), null, ['job' => Job::class]);

        $this->assertTrue($registry->has('job'));
        $this->assertSame(['job'], $registry->keys());
        $this->assertSame('job', $registry->get('job')->table);
        $this->assertSame('relation', $registry->get('job')->fields['company']->type);
        $this->assertSame(Job::class, $registry->classFor('job'));
        $this->assertNull($registry->classFor('other'));
    }

    public function test_the_admin_file_adds_presentation(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'label' => 'Jobs', 'list' => ['columns' => ['title']],
            'fields' => ['title' => ['label' => 'Job title', 'readonly' => true]]]);
        $registry = new TypeRegistry($dir, null, ['job' => Job::class]);

        $this->assertSame('Job title', $registry->get('job')->fields['title']->label);
        $this->assertTrue($registry->get('job')->fields['title']->config['readonly']);
        $this->assertSame('Jobs', $registry->schema('job')['label']);
        $this->assertSame(['required'], $registry->get('job')->fields['title']->validate, 'the class keeps its schema');
        $this->assertSame(['job'], $registry->keys(), 'a class and its file are one type');
    }

    public function test_a_file_redefining_the_schema_is_an_error(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'fields' => ['title' => ['type' => 'text']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job.json');
        $this->expectExceptionMessage('"type"');
        $this->expectExceptionMessage('defined by ' . Job::class);
        (new TypeRegistry($dir, null, ['job' => Job::class]))->get('job');
    }

    public function test_a_file_with_a_table_is_an_error(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'table' => 'jobs']);

        $this->expectExceptionMessage('"table"');
        (new TypeRegistry($dir, null, ['job' => Job::class]))->get('job');
    }

    public function test_a_file_for_a_field_the_class_lacks_is_an_error(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'fields' => ['nope' => ['label' => 'Nope']]]);

        $this->expectExceptionMessage('"nope"');
        (new TypeRegistry($dir, null, ['job' => Job::class]))->get('job');
    }

    public function test_a_slim_file_without_a_class_is_an_error(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'label' => 'Jobs']);

        $this->expectExceptionMessage('no model class');
        (new TypeRegistry($dir))->get('job');
    }

    public function test_a_patch_file_without_a_class_is_still_no_type(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'extends' => true, 'fields' => ['x' => ['type' => 'string']]]);

        $this->assertFalse((new TypeRegistry($dir))->has('job'));
    }

    public function test_json_types_are_unchanged(): void
    {
        $schema = ['key' => 'page', 'table' => 'pages', 'fields' => ['title' => ['type' => 'string']]];
        $registry = new TypeRegistry($this->dirWith('page.json', $schema), null, ['job' => Job::class]);

        $this->assertSame('pages', $registry->get('page')->table);
        $this->assertSame($schema, $registry->schema('page'));
        $this->assertSame(['job', 'page'], $registry->keys());
        $this->assertNull($registry->classFor('page'));
    }

    public function test_define_refuses_a_class_key(): void
    {
        $registry = new TypeRegistry($this->emptyDir(), null, ['job' => Job::class]);

        $this->expectExceptionMessage('defined by ' . Job::class);
        $registry->define(['key' => 'job', 'table' => 'job']);
    }

    public function test_labels_of_a_class_type_are_derived_like_a_json_types(): void
    {
        $dir = $this->dirWith('job.json', ['key' => 'job', 'label' => 'Jobs']);
        $keys = \Preflow\Data\ModelLabelKeys::classTypes(new TypeRegistry($dir, null, ['job' => Job::class]));

        $this->assertSame('Jobs', $keys['site.types.job.label']);
        $this->assertSame('Title', $keys['site.fields.job.title.label']);
    }
}
