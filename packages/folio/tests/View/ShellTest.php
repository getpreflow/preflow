<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\View;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Folio\Tests\Support\StaffScaffold;

/**
 * The admin shell (_layout.twig, partials/_user_menu.twig, partials/_rail.twig): the sidebar with the
 * site switcher, the nav and the user menu on list and settings pages; the icon rail on editors
 * ({% set shell = 'rail' %}); the topbar with breadcrumb, title and the subtitle line that replaced
 * the help banners.
 */
final class ShellTest extends TestCase
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

    private function signIn(): void
    {
        $this->makeStaff();
        $this->assertSame('/folio', $this->post('/folio/login', ['email' => 'ada@site.test', 'password' => self::PASSWORD])->getHeaderLine('Location'));
    }

    private function dom(string $uri): \DOMXPath
    {
        $html = $this->html($uri);
        $doc = new \DOMDocument();
        $this->assertTrue(@$doc->loadHTML($html, LIBXML_NOERROR), 'the page parses');

        return new \DOMXPath($doc);
    }

    private function one(\DOMXPath $x, string $query, string $what): \DOMElement
    {
        $nodes = $x->query($query);
        $this->assertNotFalse($nodes);
        $this->assertCount(1, $nodes, $what);
        $node = $nodes->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }

    private static function cls(string $name): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$name} ')";
    }

    #[RunInSeparateProcess]
    public function test_the_dashboard_has_the_sidebar_with_switcher_nav_and_user_menu(): void
    {
        $this->signIn();
        $x = $this->dom('/folio');

        $shell = $this->one($x, '//div[' . self::cls('folio-shell') . ']', 'one shell');
        $this->assertStringNotContainsString('is-rail', $shell->getAttribute('class'), 'a dashboard has the sidebar');
        $this->assertSame(0, $x->query('//*[' . self::cls('folio-rail') . ']')->length, 'and no rail');

        // Site switcher: the ink tile with the logo, the site name, the environment line (production says only "Folio").
        $brand = $this->one($x, '//aside[@class="folio-sidebar"]//a[' . self::cls('folio-brand') . ']', 'the site switcher');
        $this->assertSame('/folio', $brand->getAttribute('href'));
        $this->one($x, '//a[' . self::cls('folio-brand') . ']/span[' . self::cls('folio-brand-tile') . ' and ' . self::cls('folio-ink') . ']/*[local-name()="svg" and ' . self::cls('folio-logo') . ']', 'the logo on the ink tile');
        $this->assertSame('Staff Site', trim($this->one($x, '//*[' . self::cls('folio-brand-name') . ']', 'site name')->textContent));
        $this->assertSame('Folio', trim($this->one($x, '//*[' . self::cls('folio-brand-env') . ']', 'environment')->textContent));
        $this->assertSame(0, $x->query('//*[' . self::cls('folio-site') . ']//*[local-name()="use" and contains(@href, "ui-updown")]')->length, 'one site: no chevron');

        // Nav: the dashboard item is current; sections keep data-nav-save.
        $this->one($x, '//nav[@class="folio-nav" and @data-nav-save="/folio/_prefs/nav"]', 'the nav, saving its sections');
        $current = $this->one($x, '//nav[@class="folio-nav"]//a[@aria-current="page"]', 'one current item');
        $this->assertSame('/folio', $current->getAttribute('href'));
        $this->assertGreaterThan(0, $x->query('//nav[@class="folio-nav"]//details[' . self::cls('folio-nav-section') . ']/summary')->length, 'collapsible sections');

        // User menu: one button that opens a dialog with the account link, theme, language and Log out.
        $button = $this->one($x, '//button[@id="folio-user-button-sidebar"]', 'the user button');
        $this->assertSame('dialog', $button->getAttribute('aria-haspopup'));
        $this->assertSame('false', $button->getAttribute('aria-expanded'));
        $this->assertSame('folio-user-panel-sidebar', $button->getAttribute('aria-controls'));
        $this->assertSame('ada@site.test', trim($this->one($x, '//button[@id="folio-user-button-sidebar"]/span[' . self::cls('folio-user-email') . ']', 'email')->textContent));
        $panel = $this->one($x, '//*[@id="folio-user-panel-sidebar"]', 'the panel');
        $this->assertSame('dialog', $panel->getAttribute('role'));
        $this->assertSame(0, $x->query('//*[@role="menu" or @role="menuitem"]')->length, 'no menu roles around toggle groups');
        $this->one($x, '//*[@id="folio-user-panel-sidebar"]//a[@href="/folio/_account" and @data-menu-row]', 'Account & password');
        $this->one($x, '//*[@id="folio-user-panel-sidebar"]//div[@id="folio-theme-toggle" and @role="group" and @data-theme-switch]', 'the theme switch');
        $this->assertSame(3, $x->query('//div[@id="folio-theme-toggle"]/button[@data-theme-choice and @aria-pressed]')->length);
        $this->one($x, '//*[@id="folio-user-panel-sidebar"]//form[@action="/folio/_locale" and @method="post"]', 'the language switch');
        $logout = $this->one($x, '//*[@id="folio-user-panel-sidebar"]//form[@action="/folio/logout" and @method="post"]', 'Log out posts');
        $this->assertSame(1, $x->query('.//input[@name="_csrf_token"]', $logout)->length, 'with the CSRF token');
        $this->assertSame(1, $x->query('.//button[@type="submit" and @data-menu-row]', $logout)->length);
        $this->assertSame(0, $x->query('//aside[@class="folio-sidebar"]/div[' . self::cls('folio-sidebar-foot') . ']/*[' . self::cls('folio-theme-switch') . ']')->length, 'no separate switches in the foot any more');

        // Topbar: the title, no subtitle on the dashboard.
        $this->one($x, '//header[' . self::cls('folio-topbar') . ']//h1', 'the title');
        $this->assertSame(0, $x->query('//*[' . self::cls('folio-subtitle') . ']')->length);
    }

    #[RunInSeparateProcess]
    public function test_an_editor_has_the_rail_with_labelled_icon_links(): void
    {
        $this->signIn();
        $x = $this->dom('/folio/page/new');

        $shell = $this->one($x, '//div[' . self::cls('folio-shell') . ']', 'one shell');
        $this->assertStringContainsString('is-rail', $shell->getAttribute('class'));

        $rail = $this->one($x, '//aside[' . self::cls('folio-rail') . ']', 'the rail');
        $this->one($x, '//aside[' . self::cls('folio-rail') . ']/a[' . self::cls('folio-rail-brand') . ' and ' . self::cls('folio-ink') . ' and @href="/folio" and @aria-label]', 'the brand tile');
        $items = $x->query('//nav[' . self::cls('folio-rail-nav') . ']/a[' . self::cls('folio-rail-item') . ']');
        $this->assertNotFalse($items);
        $sidebarItems = $x->query('//nav[@class="folio-nav"]//a');
        $this->assertGreaterThan(1, $items->length);
        $this->assertSame($sidebarItems->length, $items->length, 'the same items as the sidebar');
        foreach ($items as $item) {
            $this->assertInstanceOf(\DOMElement::class, $item);
            $this->assertNotSame('', $item->getAttribute('href'), 'a plain link (works without script)');
            $this->assertNotSame('', $item->getAttribute('aria-label'));
            $this->assertSame($item->getAttribute('title'), $item->getAttribute('aria-label'));
            $this->assertSame('', trim($item->textContent), 'an icon only');
        }
        $current = $this->one($x, '//nav[' . self::cls('folio-rail-nav') . ']/a[@aria-current="page"]', 'the current type');
        $this->assertSame('/folio/page', $current->getAttribute('href'));
        $this->assertSame('Pages', $current->getAttribute('aria-label'));

        $expand = $this->one($x, '//aside[' . self::cls('folio-rail') . ']/button[@data-shell-expand]', 'the expand button');
        $this->assertNotSame('', $expand->getAttribute('aria-label'));
        $this->one($x, '//aside[@class="folio-sidebar"]//button[@data-shell-collapse]', 'the sidebar can go back to the rail');

        $avatar = $this->one($x, '//button[@id="folio-user-button-rail"]', 'the rail avatar opens the user menu');
        $this->assertSame('dialog', $avatar->getAttribute('aria-haspopup'));
        $this->assertSame('false', $avatar->getAttribute('aria-expanded'));
        $this->assertStringContainsString('ada@site.test', $avatar->getAttribute('aria-label'));
        $this->one($x, '//*[@id="folio-user-panel-rail"]//form[@action="/folio/logout"]', 'with Log out');
        $this->assertSame(1, $x->query('//*[@id="folio-theme-toggle"]')->length, 'ids stay unique with two menus');

        // The topbar keeps the breadcrumb over the title.
        $this->one($x, '//header[' . self::cls('folio-topbar') . ']//nav[' . self::cls('folio-breadcrumb') . ']/a[@href="/folio/page"]', 'the breadcrumb');
    }

    #[RunInSeparateProcess]
    public function test_help_banners_are_the_subtitle_line_under_the_title(): void
    {
        $this->signIn();

        foreach (['/folio/translations' => 'Change the interface texts', '/folio/cache' => null] as $uri => $start) {
            $x = $this->dom($uri);
            $subtitle = $this->one($x, '//header[' . self::cls('folio-topbar') . ']/div[' . self::cls('folio-topbar-title') . ']/h1/following-sibling::p[' . self::cls('folio-subtitle') . ']', $uri . ': the subtitle under the title');
            $this->assertNotSame('', trim($subtitle->textContent));
            if ($start !== null) {
                $this->assertStringStartsWith($start, trim($subtitle->textContent));
            }
            $this->assertSame(0, $x->query('//main//div[' . self::cls('folio-notice') . ' and @role="status"]/p[normalize-space(.)="' . trim($subtitle->textContent) . '"]')->length, $uri . ': no banner with the same text');
        }
    }

    #[RunInSeparateProcess]
    public function test_the_environment_line_names_a_non_production_environment(): void
    {
        $this->write('config/app.php', "<?php\nreturn " . var_export(['name' => 'Staff Site', 'debug' => 0, 'env' => 'staging', 'engine' => 'twig', 'key' => 'folio-staff-test-key', 'url' => 'https://site.test'], true) . ";\n");
        $this->flow = $this->app();
        $this->signIn();

        $this->assertSame('Folio · staging', trim($this->one($this->dom('/folio'), '//*[' . self::cls('folio-brand-env') . ']', 'environment')->textContent));
    }
}
