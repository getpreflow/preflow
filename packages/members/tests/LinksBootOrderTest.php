<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Core\Container\Container;
use Preflow\Core\Container\ServiceProvider;
use Preflow\Core\Http\LocaleUrls;
use Preflow\I18n\Translator;
use Preflow\Members\Links;
use Preflow\Members\Tests\Support\MembersScaffold;

/**
 * Members' Links is a lazy singleton that takes core's LocaleUrls without a fallback: the
 * core binds LocaleUrls (and the translator members adds its texts to) before any provider
 * registers, so even a provider booting first that asks for Links gets the site's rule.
 */
final class LinksBootOrderTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        LinksProbeProvider::$local = null;
        $this->scaffold();
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix', 'prefix_default' => true];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\" . LinksProbeProvider::class . "::class, \\Preflow\\Members\\MembersServiceProvider::class];\n");
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_links_resolved_in_the_first_provider_boot_follow_locale_urls(): void
    {
        $container = $this->app()->container();

        $this->assertSame('/en/members/login', LinksProbeProvider::$local);
        $this->assertSame('/de/members', $container->get(Links::class)->local('de'));
        $this->assertTrue($container->get(LocaleUrls::class)->prefixDefault);
    }

    public function test_members_texts_go_to_the_core_translator(): void
    {
        $container = $this->app()->container();
        $translator = $container->get(Translator::class);
        $this->assertSame('Anmelden', $translator->get('members.login.title', [], 'de'));
    }
}

/** Boots before the members provider and asks for Links at once. */
final class LinksProbeProvider extends ServiceProvider
{
    public static ?string $local = null;

    public function register(Container $container): void
    {
    }

    public function boot(Container $container): void
    {
        self::$local = $container->get(Links::class)->local('en', '/login');
    }
}
