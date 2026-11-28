<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Preview;

use Preflow\Blocks\Preview\PreviewConfigException;
use Preflow\Blocks\Preview\TypePreviews;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Blocks\Tests\Fixtures\Preview\PreviewCard;
use Preflow\Components\ComponentMap;
use Preflow\Data\TypeRegistry;

/**
 * The boot check `blocks.type_previews`: a type's "preview" that cannot work stops the boot, with or
 * without Folio — its shape, and a component that neither the component map nor a class gives.
 */
final class TypePreviewsBootTest extends BlocksAppTestCase
{
    /** @param array<string, array<string, mixed>> $types key => the model file's extra keys */
    private function types(array $types): void
    {
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json', 'models_path' => __DIR__ . '/models'];\n");
        foreach ($types as $key => $extra) {
            $this->write('config/models/' . $key . '.json', (string) json_encode([
                'key' => $key, 'table' => $key, 'storage' => 'json', 'id_field' => 'uuid',
                'fields' => ['title' => ['type' => 'string']],
            ] + $extra));
        }
    }

    public function test_missing_component_fails_the_boot_with_the_message_of_call_4(): void
    {
        $this->types(['job' => ['preview' => ['views' => ['card' => ['label' => 'Card', 'component' => 'JobCrd']]]]]);

        $this->expectException(PreviewConfigException::class);
        $this->expectExceptionMessage('Type "job": preview view "card" names the component "JobCrd", which does not exist. Create app/Components/JobCrd/JobCrd.php, or name an existing component.');
        $this->app();
    }

    public function test_a_missing_class_or_a_class_that_is_no_component_fails_naming_the_class(): void
    {
        $previews = fn (string $component): TypePreviews => new TypePreviews($this->registry(['job' => ['preview' => ['component' => $component]]]), new ComponentMap([]));

        try {
            $previews('App\\Components\\Gone')->assertTypes();
            $this->fail('a missing class passed');
        } catch (PreviewConfigException $e) {
            $this->assertSame('Type "job": preview view "default" names the component "App\\Components\\Gone", which does not exist. Create the class, extending Preflow\\Components\\Component, or name an existing component.', $e->getMessage());
        }
        try {
            $previews(\stdClass::class)->assertTypes();
            $this->fail('a class that is no component passed');
        } catch (PreviewConfigException $e) {
            $this->assertSame('Type "job": preview view "default" names the class "stdClass", which is no component. Extend Preflow\\Components\\Component, or name an existing component.', $e->getMessage());
        }
    }

    public function test_a_shape_refusal_fails_the_boot(): void
    {
        $this->types(['job' => ['preview' => ['views' => []]]]);

        $this->expectException(PreviewConfigException::class);
        $this->expectExceptionMessage('Type "job": "preview.views" must be a non-empty object of views');
        $this->app();
    }

    public function test_component_given_as_a_class_is_accepted(): void
    {
        $this->types(['job' => ['preview' => ['views' => [
            'card' => ['component' => PreviewCard::class],
            'banner' => ['component' => 'Banner'],
        ]]]]);

        $previews = $this->app()->container()->get(TypePreviews::class);

        $this->assertSame(PreviewCard::class, $previews->forType('job')?->view('card')->component);
        $this->assertSame('Banner', $previews->forType('job')?->view('banner')->component, 'a name in the component map');
    }

    public function test_a_type_with_a_route_and_a_preview_boots(): void
    {
        $this->types(['post' => ['frontend' => ['route' => '/posts/{slug}'], 'preview' => ['component' => 'Banner']]]);

        $previews = $this->app()->container()->get(TypePreviews::class);

        $this->assertSame(['default'], $previews->forType('post')?->keys(), 'allowed: the route\'s preview wins, "preview" adds views');
    }

    public function test_types_without_preview_are_unchanged(): void
    {
        $this->types(['page' => []]);

        $previews = $this->app()->container()->get(TypePreviews::class);

        $this->assertNull($previews->forType('page'));
        $this->assertNull($previews->forType('unknown'), 'a type the registry does not have');
        $previews->assertTypes();
    }

    public function test_a_site_binding_of_type_previews_is_kept(): void
    {
        $this->types(['page' => []]);
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Blocks\\Tests\\Fixtures\\Preview\\OwnTypePreviews::class, \\Preflow\\Blocks\\BlocksServiceProvider::class];\n");
        \Preflow\Blocks\Tests\Fixtures\Preview\OwnTypePreviews::$built = null;

        $previews = $this->app()->container()->get(TypePreviews::class);

        $this->assertSame(\Preflow\Blocks\Tests\Fixtures\Preview\OwnTypePreviews::$built, $previews);
    }

    /** @param array<string, array<string, mixed>> $types */
    private function registry(array $types): TypeRegistry
    {
        $dir = $this->dir . '/registry_' . bin2hex(random_bytes(4)); // removed with the app's folder
        foreach ($types as $key => $extra) {
            $this->write(substr($dir, strlen($this->dir) + 1) . '/' . $key . '.json', (string) json_encode(['key' => $key, 'table' => $key, 'fields' => ['title' => ['type' => 'string']]] + $extra));
        }

        return new TypeRegistry($dir);
    }
}
