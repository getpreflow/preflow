<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\Members\MembersConfig;
use Preflow\Members\Tests\Support\MemberFlows;

/**
 * Folio offers its frontend layout (@folio/frontend/_site.twig, the site's resources/folio/frontend/_site.twig)
 * to the member pages only when the site has one; a fresh install without it gets the members' standalone page.
 */
final class MembersLayoutDefaultTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->removeScaffold();
        }
    }

    #[RunInSeparateProcess]
    public function test_a_site_without_a_folio_frontend_layout_gets_the_standalone_members_page(): void
    {
        $this->flowSite();
        unlink($this->dir . '/resources/folio/frontend/_site.twig');
        $this->flow = $this->app();

        $response = $this->send('GET', '/members/register');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringNotContainsString('Site header', (string) $response->getBody());
        $this->assertSame('@members/_layout.twig', $this->flow->container()->get(MembersConfig::class)->layout($this->flow->container()->get(\Preflow\View\TemplateEngineInterface::class)));
    }

    #[RunInSeparateProcess]
    public function test_a_site_with_a_folio_frontend_layout_keeps_using_it(): void
    {
        $this->flowSite();

        $response = $this->send('GET', '/members/register');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Site header', (string) $response->getBody());
        $this->assertSame('@folio/frontend/_site.twig', $this->flow->container()->get(MembersConfig::class)->layout($this->flow->container()->get(\Preflow\View\TemplateEngineInterface::class)));
    }

    #[RunInSeparateProcess]
    public function test_a_layout_in_config_members_wins(): void
    {
        $this->flowSite(['layout' => '@members/_layout.twig']);

        $this->assertSame('@members/_layout.twig', $this->flow->container()->get(MembersConfig::class)->layout($this->flow->container()->get(\Preflow\View\TemplateEngineInterface::class)));
    }

    #[RunInSeparateProcess]
    public function test_the_layout_is_found_whatever_order_the_providers_boot_in(): void
    {
        $this->flowSite();
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class];\n");
        $this->flow = $this->app();

        $response = $this->send('GET', '/members/register');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Site header', (string) $response->getBody());
    }
}
