<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests\Layouts;

use Preflow\Blocks\Layouts\LayoutConfigException;
use Preflow\Blocks\Layouts\LayoutRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Layouts\ResolvedLayout;
use Preflow\Blocks\Tests\BlocksAppTestCase;
use Preflow\Blocks\Tests\Fixtures\EntityLayouts\Valid\LayoutPage;
use Preflow\Blocks\Tests\Fixtures\Layouts\Home;
use Preflow\Blocks\Tests\Fixtures\Layouts\PackageHome;
use Preflow\Blocks\Tests\Fixtures\Layouts\Promo;
use Preflow\Blocks\Tests\Fixtures\Layouts\Standard;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Locales;
use Preflow\Data\TypeRegistry;

/**
 * Page layouts declared in code (#[Entity(layouts: …)]) are the JSON option "layouts": checked at
 * boot by blocks' `blocks.type_layouts` check with the same messages, and resolved the same way.
 */
final class EntityLayoutsBootTest extends BlocksAppTestCase
{
    private function data(string $models): void
    {
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'drivers' => ['json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data']],
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'model_paths' => [dirname(__DIR__) . '/Fixtures/EntityLayouts/' . $models],
        ], true) . ";\n");
        @mkdir($this->dir . '/config/models', 0777, true);
    }

    public function test_entity_layouts_are_checked_at_boot_like_the_json_option(): void
    {
        $this->data('Broken');

        $this->expectException(LayoutConfigException::class);
        $this->expectExceptionMessage('Type "x": "layouts.default" is "standard", which "layouts.allowed" does not list (landing); choose one of them, or add it to "layouts.allowed".');
        $this->app();
    }

    public function test_a_booted_app_reads_entity_layouts(): void
    {
        $this->data('Valid');

        $layouts = $this->app()->container()->get(PageLayouts::class);

        $this->assertSame(['promo', 'standard', 'gone'], $layouts->forType('el_page')?->allowed);
        $this->assertSame('standard', $layouts->forType('el_page')?->default);
    }

    public function test_entity_layouts_resolve_like_the_json_option(): void
    {
        $models = $this->dir . '/models';
        @mkdir($models, 0777, true);
        // The same option as a model file, for a type of its own.
        file_put_contents($models . '/json_page.json', (string) json_encode([
            'key' => 'json_page', 'table' => 'json_page', 'storage' => 'json', 'id_field' => 'uuid',
            'fields' => ['title' => ['type' => 'string'], 'slug' => ['type' => 'string', 'translatable' => true]],
            'layouts' => \Preflow\Data\ModelSchema::of(LayoutPage::class)['layouts'],
        ]));
        $types = new TypeRegistry($models, new Locales(['en', 'de'], 'en'), ['el_page' => LayoutPage::class]);
        $layouts = new PageLayouts(new LayoutRegistry([Standard::class, Promo::class, Home::class, PackageHome::class]), $types);
        $layouts->assertTypes();
        $summary = static fn (?ResolvedLayout $r): array => [$r?->layout?->key, $r?->storedKey, $r?->pinned, $r?->fallback];

        foreach ([
            ['slug' => 'home', '_layout' => 'promo'],
            ['slug' => 'legacy', '_layout' => 'promo'],
            ['slug' => 'about', '_layout' => 'promo'],
            ['_layout' => 'gone'],
            ['_layout' => 'package_home'],
            [],
        ] as $values) {
            $class = $layouts->resolve(DynamicRecord::fromArray($types->get('el_page'), ['uuid' => 'r1'] + $values));
            $json = $layouts->resolve(DynamicRecord::fromArray($types->get('json_page'), ['uuid' => 'r1'] + $values));
            $this->assertSame($summary($json), $summary($class), json_encode($values) ?: '');
        }
        $this->assertSame(['home', 'promo', true, false], $summary($layouts->resolve(DynamicRecord::fromArray($types->get('el_page'), ['uuid' => 'r1', 'slug' => 'home', '_layout' => 'promo']))), 'a pin wins');
        $this->assertSame(['promo', 'standard'], array_map(static fn ($l): string => $l->key, $layouts->selectable('el_page')));
    }
}
