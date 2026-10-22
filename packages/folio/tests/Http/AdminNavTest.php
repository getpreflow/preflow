<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\TestCase;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Http\AdminNav;

final class AdminNavTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/folio_nav_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*.json') ?: []);
        rmdir($this->dir);
    }

    /** @param list<string> $denied */
    private function nav(array $denied = []): AdminNav
    {
        file_put_contents($this->dir . '/page.json', json_encode(['key' => 'page', 'label' => 'Pages', 'fields' => []]));
        $catalog = new TypeCatalog($this->dir);
        $catalog->add(['key' => 'site', 'label' => 'Site', 'singleton' => true], 'settings');
        return new AdminNav($catalog, static fn (string $permission): bool => !in_array($permission, $denied, true), '/folio');
    }

    public function test_sections_in_fixed_order_with_extension_items(): void
    {
        $nav = $this->nav();
        $nav->add('library', 'Gallery', '/folio/gallery', 'ui-file', null);
        $nav->add('Members', 'Members', '/folio/members', 'ui-file', null);
        $nav->add('content', 'Early', '/folio/early', 'ui-file', null, 10);
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Content', 'Library', 'Settings', 'Members'], array_column($sections, 'label'));
        $labels = static fn (array $section): array => array_column($section['items'], 'label');
        $this->assertSame(['Dashboard'], $labels($sections[0]));
        $this->assertSame(['Early', 'Pages'], $labels($sections[1]));
        $this->assertSame(['Assets', 'Gallery'], $labels($sections[2]));
        $this->assertSame(['Site'], $labels($sections[3]));
        $this->assertSame('ui-settings', $sections[3]['items'][0]['icon']);
        $this->assertSame('ui-file', $sections[1]['items'][1]['icon']);
    }

    public function test_longest_prefix_is_active(): void
    {
        $nav = $this->nav();
        $nav->add('library', 'Gallery', '/folio/assets/gallery', 'ui-file', null);

        $active = function (string $path) use ($nav): array {
            $nav->setCurrentPath($path);
            $names = [];
            foreach ($nav->sections() as $section) {
                foreach ($section['items'] as $item) {
                    if ($item['active']) {
                        $names[] = $item['label'];
                    }
                }
            }
            return $names;
        };

        $this->assertSame(['Dashboard'], $active('/folio'));
        $this->assertSame(['Pages'], $active('/folio/page/abc/edit'));
        $this->assertSame(['Assets'], $active('/folio/assets/42'));
        $this->assertSame(['Gallery'], $active('/folio/assets/gallery/x'));
        $this->assertSame([], $active('/folio/pages-other'));
    }

    public function test_items_without_permission_hidden_and_empty_section_dropped(): void
    {
        $nav = $this->nav(['page.view', 'assets.view', 'site.view', 'secret.view']);
        $nav->add('Members', 'Members', '/folio/members', 'ui-file', null);
        $nav->add('Hidden', 'Secret', '/folio/secret', 'ui-file', 'secret.view');
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Members'], array_column($sections, 'label'));
        $this->assertSame(['Dashboard'], array_column($sections[0]['items'], 'label'));
    }

    public function test_settings_section_types_listed_under_settings(): void
    {
        $nav = $this->nav();
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Content', 'Library', 'Settings'], array_column($sections, 'label'));
        $this->assertSame(['Pages'], array_column($sections[1]['items'], 'label'));
        $this->assertSame(['Site'], array_column($sections[3]['items'], 'label'));
    }

    public function test_singleton_form_path_marks_item_active(): void
    {
        $nav = $this->nav();
        $nav->setCurrentPath('/folio/site');

        $active = [];
        foreach ($nav->sections() as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    $active[] = $item['label'];
                }
            }
        }

        $this->assertSame(['Site'], $active);
    }
}
