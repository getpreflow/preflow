<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Tests\Fixtures\ModelSchema\Job;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeRegistry;

/** matrixable and views are admin settings: the data package keeps them in the schema and does not interpret them. */
final class TypeMatrixableTest extends TestCase
{
    private string $dir;

    public static function setUpBeforeClass(): void
    {
        foreach (glob(__DIR__ . '/Fixtures/ModelSchema/*.php') as $fixture) {
            require_once $fixture;
        }
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/typematrix_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/note.json', json_encode([
            'key' => 'note', 'table' => 'note', 'storage' => 'json', 'id_field' => 'uuid',
            'matrixable' => true, 'views' => ['card', 'inline', 42],
            'fields' => ['name' => ['type' => 'string']],
        ]));
        file_put_contents($this->dir . '/page.json', json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.json'));
        rmdir($this->dir);
    }

    public function test_type_definition_has_no_matrix_keys(): void
    {
        $this->assertFalse(property_exists(TypeDefinition::class, 'matrixable'));
        $this->assertFalse(property_exists(TypeDefinition::class, 'views'));
    }

    public function test_schema_keeps_matrixable_and_views(): void
    {
        $reg = new TypeRegistry($this->dir);

        $this->assertTrue($reg->schema('note')['matrixable']);
        $this->assertSame(['card', 'inline', 42], $reg->schema('note')['views'], 'the schema is the file as written');
        $this->assertArrayNotHasKey('matrixable', $reg->schema('page'));
        $this->assertArrayNotHasKey('views', $reg->schema('page'));
    }

    public function test_class_type_admin_file_may_set_matrixable(): void
    {
        file_put_contents($this->dir . '/job.json', json_encode(['key' => 'job', 'matrixable' => true, 'views' => ['card']]));

        $reg = new TypeRegistry($this->dir, null, ['job' => Job::class]);

        $this->assertSame('job', $reg->get('job')->table);
        $this->assertTrue($reg->schema('job')['matrixable']);
        $this->assertSame(['card'], $reg->schema('job')['views']);
    }
}
