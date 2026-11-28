<?php

declare(strict_types=1);

namespace Preflow\Blocks\Tests;

use Preflow\Blocks\BlockRegistry;
use Preflow\Blocks\Layouts\PageLayouts;
use Preflow\Blocks\Preview\TypePreviews;
use Preflow\Data\DataManager;
use Preflow\Data\TypeRegistry;

/** An app with no type registry (no models directory, no Folio) still boots the blocks provider (0.94). */
final class BootWithoutTypesTest extends BlocksAppTestCase
{
    public function test_the_provider_boots_without_a_type_registry(): void
    {
        // A data layer with no models directory: a DataManager is bound, without a registry.
        $this->write('config/data.php', "<?php\nreturn ['drivers' => ['json' => ['driver' => \\Preflow\\Data\\Driver\\JsonFileDriver::class, 'path' => __DIR__ . '/../storage/data']], 'default' => 'json'];\n");
        $container = $this->app()->container();

        $this->assertFalse($container->bound(TypeRegistry::class));
        $this->assertTrue($container->bound(DataManager::class));
        $this->assertNull($container->get(DataManager::class)->getTypeRegistry());
        $this->assertFalse($container->bound(TypePreviews::class), 'no types, no previews');
        $this->assertFalse($container->bound(PageLayouts::class), 'no types, no page layouts');
        $this->assertInstanceOf(BlockRegistry::class, $container->get(BlockRegistry::class));
    }
}
