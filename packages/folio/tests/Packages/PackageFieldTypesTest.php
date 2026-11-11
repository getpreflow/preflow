<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Packages;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Data\DataManager;
use Preflow\Data\Schema\ColumnKind;
use Preflow\Folio\Block\Block;
use Preflow\Folio\Block\BlockField;
use Preflow\Folio\Block\BlockRegistry;
use Preflow\Folio\Field\FieldTypeRegistry;
use Preflow\Folio\Field\HasColumnKind;
use Preflow\Folio\Field\SearchableText;
use Preflow\Folio\Field\Types\AbstractScalarFieldType;
use Preflow\Folio\Tests\Support\PublishingScaffold;

/**
 * A package adds field types and blocks by being installed (0.66): composer.json
 * extra.folio.field_types / extra.folio.blocks, treated like config/folio.php's (field kinds:
 * SearchableText, HasColumnKind). A type a provider registers later gets its kinds too.
 */
final class PackageFieldTypesTest extends TestCase
{
    use PublishingScaffold;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/folio-links',
            'extra' => ['folio' => [
                'field_types' => [PackageLinkFieldType::class],
                'blocks' => [PackageCtaBlock::class],
            ]],
        ]]]));
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function booted(): Application
    {
        $app = Application::create($this->dir);
        $app->boot();

        return $app;
    }

    #[RunInSeparateProcess]
    public function test_an_installed_package_s_field_types_and_blocks_are_registered_with_their_kinds(): void
    {
        $c = $this->booted()->container();

        $type = $c->get(FieldTypeRegistry::class)->get('pkglink');
        $this->assertInstanceOf(PackageLinkFieldType::class, $type);
        $this->assertInstanceOf(LinkPolicy::class, $type->policy, 'built by the container');
        $kinds = $c->get(DataManager::class)->fieldKinds();
        $this->assertTrue($kinds->isText('pkglink'), 'SearchableText reaches the data layer');
        $field = new \Preflow\Data\TypeFieldDefinition('website', 'pkglink');
        $this->assertSame(ColumnKind::Number, $kinds->columnKindOf($field), 'HasColumnKind reaches the data layer');

        $block = $c->get(BlockRegistry::class)->get('pkg_cta');
        $this->assertNotNull($block);
        $this->assertNotNull($c->get(BlockRegistry::class)->get('hero'), 'config/folio.php blocks stay');
    }

    #[RunInSeparateProcess]
    public function test_the_app_s_config_wins_over_a_package_for_the_same_key(): void
    {
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio', 'field_types' => [\\" . AppLinkFieldType::class . "::class]];\n");

        $type = $this->booted()->container()->get(FieldTypeRegistry::class)->get('pkglink');

        $this->assertInstanceOf(AppLinkFieldType::class, $type);
    }

    #[RunInSeparateProcess]
    public function test_a_type_a_provider_registers_after_folio_boots_gets_its_kinds(): void
    {
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\" . LateTypeProvider::class . "::class];\n");

        $c = $this->booted()->container();

        $this->assertInstanceOf(LateFieldType::class, $c->get(FieldTypeRegistry::class)->get('late'));
        $this->assertTrue($c->get(DataManager::class)->fieldKinds()->isText('late'));
    }

    #[RunInSeparateProcess]
    public function test_a_listed_class_that_is_no_field_type_stops_the_boot(): void
    {
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [[
            'name' => 'acme/broken',
            'extra' => ['folio' => ['field_types' => [\stdClass::class]]],
        ]]]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('acme/broken');
        $this->booted()->container()->get(FieldTypeRegistry::class);
    }
}

/** A service the package's field type takes. */
final class LinkPolicy
{
}

final class PackageLinkFieldType extends AbstractScalarFieldType implements SearchableText, HasColumnKind
{
    public function __construct(public readonly LinkPolicy $policy)
    {
        parent::__construct();
    }

    public function key(): string
    {
        return 'pkglink';
    }

    protected function inputType(): string
    {
        return 'url';
    }

    public function columnKind(array $config): ColumnKind
    {
        return ColumnKind::Number;
    }
}

final class AppLinkFieldType extends AbstractScalarFieldType
{
    public function key(): string
    {
        return 'pkglink';
    }

    protected function inputType(): string
    {
        return 'url';
    }
}

final class LateFieldType extends AbstractScalarFieldType implements SearchableText
{
    public function key(): string
    {
        return 'late';
    }

    protected function inputType(): string
    {
        return 'text';
    }
}

final class LateTypeProvider extends ServiceProvider
{
    public function register(Container $container): void {}

    public function boot(Container $container): void
    {
        $container->get(FieldTypeRegistry::class)->register(new LateFieldType());
    }
}

#[Block(key: 'pkg_cta', label: 'Call to action', icon: 'link')]
final class PackageCtaBlock
{
    #[BlockField(type: 'string')]
    public string $label = '';
}
