<?php

declare(strict_types=1);

namespace Preflow\Data\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\Attributes\Entity;
use Preflow\Data\Attributes\Field;
use Preflow\Data\Attributes\Id;
use Preflow\Data\Model;
use Preflow\Data\ModelSchema;
use Preflow\Data\TypeRegistry;

/**
 * #[Entity(layouts: …)]: a class-backed type declares its page layouts in code, written into its
 * schema as "layouts" (the JSON option's shape, which data does not read: blocks checks it). An
 * admin file may not set them a second time.
 */
final class EntityLayoutsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/preflow_entity_layouts_' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/models', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    public function test_entity_layouts_are_in_the_schema(): void
    {
        $layouts = ['allowed' => ['landing', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'landing'], 'slug_field' => 'slug'];

        $this->assertSame($layouts, ModelSchema::of(EntityLayoutsPage::class)['layouts'] ?? null, 'as declared, unread');
        $this->assertArrayNotHasKey('layouts', ModelSchema::of(EntityLayoutsPlain::class), 'none declared: no key');
        $this->assertSame(['allowed' => 'not checked here'], ModelSchema::of(EntityLayoutsOdd::class)['layouts'] ?? null, 'data does not know the shape');

        $registry = new TypeRegistry($this->dir . '/models', classes: ['el_page' => EntityLayoutsPage::class]);
        $this->assertSame($layouts, $registry->schema('el_page')['layouts'] ?? null);

        file_put_contents($this->dir . '/models/el_page.json', json_encode(['key' => 'el_page', 'label' => 'Pages']));
        $registry = new TypeRegistry($this->dir . '/models', classes: ['el_page' => EntityLayoutsPage::class]);
        $this->assertSame($layouts, $registry->schema('el_page')['layouts'] ?? null, 'with an admin file that does not set them');
        $this->assertSame('Pages', $registry->schema('el_page')['label'] ?? null);
    }

    public function test_admin_file_and_entity_both_setting_layouts_is_refused(): void
    {
        file_put_contents($this->dir . '/models/el_page.json', json_encode(['key' => 'el_page', 'layouts' => ['allowed' => ['standard'], 'default' => 'standard']]));

        try {
            new TypeRegistry($this->dir . '/models', classes: ['el_page' => EntityLayoutsPage::class]); // at boot
            $this->fail('the registry was built');
        } catch (\RuntimeException $e) {
            $this->assertSame('Model file el_page.json sets "layouts", but el_page is defined by ' . EntityLayoutsPage::class . ', whose #[Entity(layouts:)] declares them; keep one.', $e->getMessage());
        }

        try {
            ModelSchema::overlay(ModelSchema::of(EntityLayoutsPage::class), ['key' => 'el_page', 'layouts' => []], $this->dir . '/models/el_page.json', EntityLayoutsPage::class);
            $this->fail('the overlay passed');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Model file el_page.json sets "layouts", but el_page is defined by', $e->getMessage());
        }
    }

    public function test_an_admin_files_layouts_for_a_class_without_them_keep_working(): void
    {
        $layouts = ['allowed' => ['standard'], 'default' => 'standard'];
        file_put_contents($this->dir . '/models/el_plain.json', json_encode(['key' => 'el_plain', 'layouts' => $layouts]));

        $registry = new TypeRegistry($this->dir . '/models', classes: ['el_plain' => EntityLayoutsPlain::class]);

        $this->assertSame($layouts, $registry->schema('el_plain')['layouts'] ?? null, 'as in 0.92');
    }
}

#[Entity(table: 'el_pages', type: 'el_page', layouts: ['allowed' => ['landing', 'standard'], 'default' => 'standard', 'pinned' => ['home' => 'landing'], 'slug_field' => 'slug'])]
final class EntityLayoutsPage extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
    #[Field] public string $slug = '';
}

#[Entity(table: 'el_plain', type: 'el_plain')]
final class EntityLayoutsPlain extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
}

#[Entity(table: 'el_odd', type: 'el_odd', layouts: ['allowed' => 'not checked here'])]
final class EntityLayoutsOdd extends Model
{
    #[Id] public string $uuid = '';
    #[Field] public string $title = '';
}
