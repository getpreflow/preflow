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

        $this->assertSame(['', 'Content', 'Members', 'Library', 'Settings'], array_column($sections, 'label'));
        $labels = static fn (array $section): array => array_column($section['items'], 'label');
        $this->assertSame(['Dashboard'], $labels($sections[0]));
        $this->assertSame(['Early', 'Pages'], $labels($sections[1]));
        $this->assertSame(['Members'], $labels($sections[2]));
        $this->assertSame(['Assets', 'Gallery'], $labels($sections[3]));
        $this->assertSame(['Site'], $labels($sections[4]));
        $this->assertSame('ui-settings', $sections[4]['items'][0]['icon']);
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

    /** @param array<string, array<string, mixed>> $models by file name */
    private function navOf(array $models): AdminNav
    {
        foreach ($models as $name => $model) {
            file_put_contents($this->dir . '/' . $name . '.json', json_encode($model));
        }

        return new AdminNav(new TypeCatalog($this->dir), static fn (string $permission): bool => true, '/folio');
    }

    public function test_model_json_section_order_icon(): void
    {
        $nav = $this->navOf([
            'a_post' => ['key' => 'post', 'label' => 'Posts', 'fields' => []],
            'b_page' => ['key' => 'page', 'label' => 'Pages', 'section' => 'pages', 'icon' => 'ui-dashboard', 'fields' => []],
            'c_mail' => ['key' => 'mail', 'label' => 'Mail', 'section' => 'settings', 'fields' => []],
            'd_site' => ['key' => 'site', 'label' => 'Site', 'singleton' => true, 'section' => 'settings', 'order' => 10, 'fields' => []],
        ]);
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Pages', 'Content', 'Library', 'Settings'], array_column($sections, 'label'));
        $this->assertSame(['Pages'], array_column($sections[1]['items'], 'label'));
        $this->assertSame('ui-dashboard', $sections[1]['items'][0]['icon']);
        $this->assertSame(['Posts'], array_column($sections[2]['items'], 'label'));
        $this->assertSame('ui-file', $sections[2]['items'][0]['icon']);
        $this->assertSame(['Site', 'Mail'], array_column($sections[4]['items'], 'label'));
        $this->assertSame(['ui-settings', 'ui-file'], array_column($sections[4]['items'], 'icon'));
    }

    public function test_nav_false_type_hidden(): void
    {
        $nav = $this->navOf([
            'post' => ['key' => 'post', 'label' => 'Posts', 'fields' => []],
            'secret' => ['key' => 'secret', 'label' => 'Secret', 'nav' => false, 'fields' => []],
        ]);

        $sections = $nav->sections();

        $this->assertSame(['Posts'], array_column($sections[1]['items'], 'label'));
    }

    public function test_custom_section_label(): void
    {
        $nav = $this->navOf([
            'a_member' => ['key' => 'member', 'label' => 'Members', 'section' => 'member_area', 'icon' => 'ui-users', 'fields' => []],
            'b_code' => ['key' => 'code', 'label' => 'Codes', 'section' => 'access-codes', 'fields' => []],
        ]);
        $nav->add('member_area', 'Invite', '/folio/invite', 'ui-key', null, 200);

        $sections = $nav->sections();

        $this->assertSame(['', 'Member area', 'Access codes', 'Library'], array_column($sections, 'label'));
        $this->assertSame(['Members', 'Invite'], array_column($sections[1]['items'], 'label'));
        $this->assertSame('ui-users', $sections[1]['items'][0]['icon']);
    }

    public function test_without_nav_keys_unchanged(): void
    {
        $nav = $this->nav();
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Content', 'Library', 'Settings'], array_column($sections, 'label'));
        $this->assertSame(['ui-file'], array_column($sections[1]['items'], 'icon'));
        $this->assertSame(['ui-settings'], array_column($sections[3]['items'], 'icon'));
    }

    public function test_custom_sections_between_content_and_library(): void
    {
        $nav = $this->navOf([
            'a_member' => ['key' => 'member', 'label' => 'Members', 'section' => 'members', 'fields' => []],
        ]);
        $nav->add('reports', 'Sales', '/folio/sales', 'ui-file', null);
        $nav->setCurrentPath('/folio');

        $sections = $nav->sections();

        $this->assertSame(['', 'Members', 'Reports', 'Library'], array_column($sections, 'label'));
    }
}
