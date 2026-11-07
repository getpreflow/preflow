<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Http\AdminNav;
use Preflow\Folio\Tests\Support\StaffScaffold;

/** The admin layout renders each sidebar section with a heading as a <details> from the person's saved state. */
final class SidebarSectionsTest extends TestCase
{
    use StaffScaffold;

    protected function setUp(): void
    {
        $this->staffSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
    }

    #[RunInSeparateProcess]
    public function test_sections_render_as_details_from_the_saved_state(): void
    {
        $this->makeStaff();
        $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD]);
        $this->assertSame(204, $this->post('/folio/_prefs/nav', ['section' => 'settings', 'open' => '0'])->getStatusCode());
        $nav = $this->flow->container()->get(AdminNav::class);
        $nav->add('settings', 'Queue', '/folio/queue', 'ui-settings', null, 100, static fn (): int => 3);
        $nav->add('settings', 'Inbox', '/folio/inbox', 'ui-settings', null, 101, static fn (): int => 2);

        $doc = \Dom\HTMLDocument::createFromString($this->html('/folio/page'), LIBXML_NOERROR);

        $this->assertSame('/folio/_prefs/nav', $doc->querySelector('nav.folio-nav')?->getAttribute('data-nav-save'));

        $settings = $doc->querySelector('details.folio-nav-section[data-section="settings"]');
        $this->assertNotNull($settings);
        $this->assertFalse($settings->hasAttribute('open'), 'saved closed');
        $badge = $settings->querySelector('summary .folio-nav-section-badge');
        $this->assertNotNull($badge, 'the summed badge sits in the summary');
        $this->assertSame('5', trim($badge->textContent));
        $this->assertSame('5 waiting', $badge->getAttribute('aria-label'));
        $this->assertSame('Settings', trim((string) $settings->querySelector('summary .folio-nav-label')?->textContent));
        $this->assertNotNull($settings->querySelector('a[href="/folio/queue"] .folio-nav-badge'), 'item badges stay');

        $active = $doc->querySelector('nav.folio-nav a[aria-current="page"]');
        $this->assertNotNull($active);
        $current = $active->closest('details.folio-nav-section');
        $this->assertNotNull($current, 'the current screen sits in a section');
        $this->assertTrue($current->hasAttribute('open'), 'the current screen\'s section is open');

        $library = $doc->querySelector('details.folio-nav-section[data-section="library"]');
        $this->assertNotNull($library);
        $this->assertFalse($library->hasAttribute('open'), 'the site default closes library');
        $this->assertNull($library->querySelector('summary .folio-nav-section-badge'), 'no badge without counts');

        $dashboard = $doc->querySelector('nav.folio-nav a[href="/folio"]');
        $this->assertNotNull($dashboard);
        $this->assertNull($dashboard->closest('details'), 'the dashboard has no heading and stays outside');
    }

    #[RunInSeparateProcess]
    public function test_the_login_page_has_no_save_url(): void
    {
        $this->assertStringNotContainsString('data-nav-save', $this->html('/folio/login'));
    }
}
