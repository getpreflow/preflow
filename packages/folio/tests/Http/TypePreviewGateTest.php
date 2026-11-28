<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Blocks\Preview\TypePreviews;
use Preflow\Components\ComponentMap;
use Preflow\Core\Container\Container;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\FolioServiceProvider;

/**
 * The form offers a type's preview pane exactly when the preview can render it (0.94): the form
 * (AdminController::previewViews()) and the preview (PreviewController::typePreview()) get the
 * types' previews from one place, FolioServiceProvider::typePreviews(), which gives them only with
 * a canvas, that is, with the app's component layer (a bound ComponentRenderer). Without one, a
 * routeless type with "preview" has no pane, as in 0.93.
 */
final class TypePreviewGateTest extends TestCase
{
    public function test_no_type_previews_without_a_component_layer(): void
    {
        $c = new Container();
        $c->instance(TypePreviews::class, new TypePreviews(new TypeRegistry(sys_get_temp_dir() . '/folio-no-models'), new ComponentMap([])));

        $this->assertNull(self::typePreviews($c), 'a ComponentRenderer class that exists is not a component layer');
    }

    public function test_type_previews_with_a_component_layer(): void
    {
        $c = new Container();
        $previews = new TypePreviews(new TypeRegistry(sys_get_temp_dir() . '/folio-no-models'), new ComponentMap([]));
        $c->instance(TypePreviews::class, $previews);
        $c->singleton(\Preflow\Components\ComponentRenderer::class, static fn () => throw new \LogicException('not resolved to decide'));

        $this->assertSame($previews, self::typePreviews($c));
    }

    public function test_no_type_previews_without_the_blocks_binding(): void
    {
        $c = new Container();
        $c->singleton(\Preflow\Components\ComponentRenderer::class, static fn () => throw new \LogicException('not resolved to decide'));

        $this->assertNull(self::typePreviews($c));
    }

    private static function typePreviews(Container $c): ?TypePreviews
    {
        return (new \ReflectionMethod(FolioServiceProvider::class, 'typePreviews'))->invoke(null, $c);
    }
}
