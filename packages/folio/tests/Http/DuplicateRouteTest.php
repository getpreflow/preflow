<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Rbac\RbacAppScaffold;

/**
 * "Duplicate" from a list row: POST {prefix}/{type}/{id}/duplicate (CSRF token, the type's create
 * and view permissions) makes a copy through RecordWriter::duplicate() and opens its editor with a "This is a
 * copy of …" note. Refused for a user type (the copy would carry the account's password and sign-in)
 * and a singleton, on the route as in the menu. Staff from config/auth.php: admin (every permission),
 * editor (pages: view and edit, no create).
 */
final class DuplicateRouteTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->write('config/models/site.json', (string) json_encode([
            'key' => 'site', 'table' => 'site', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Site', 'singleton' => true,
            'fields' => ['tagline' => ['type' => 'string']],
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function setUpStaff(): void
    {
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'page.view', 'page.edit']);
    }

    /** @param array<string, mixed> $data */
    private function seed(string $type, string $id, array $data): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data), false);
    }

    /** @return list<string> */
    private function ids(string $type): array
    {
        return array_values(array_map(static fn (DynamicRecord $r): string => (string) $r->getId(), $this->container()->get(DataManager::class)->queryType($type)->get()->items()));
    }

    #[RunInSeparateProcess]
    public function test_post_with_csrf_creates_the_copy_and_opens_its_editor(): void
    {
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['title' => 'About']);

        $res = $this->send('POST', '/folio/page/p1/duplicate');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertMatchesRegularExpression('#^/folio/page/([0-9a-f]{32})/edit$#', $res->getHeaderLine('Location'));
        preg_match('#^/folio/page/([0-9a-f]{32})/edit$#', $res->getHeaderLine('Location'), $m);
        $copy = $this->stored('page', $m[1]);
        $this->assertNotNull($copy);
        $this->assertSame('About (copy)', $copy->get('title'), 'the list never shows two rows that read the same');
        $this->assertSame('About', $this->stored('page', 'p1')->get('title'));
        $this->assertNotNull($this->stored('page', 'p1'), 'the record itself stays');

        $editor = (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('This is a copy of “About”.', html_entity_decode($editor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    #[RunInSeparateProcess]
    public function test_the_copy_title_reads_copy_in_each_language_and_counts_on(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string', 'translatable' => true]],
        ]));
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['title' => ['en' => 'About', 'de' => 'Über uns'], '_locales' => ['en', 'de']]);

        $copy = $this->copyOf('p1');

        $this->assertSame(['en' => 'About (copy)', 'de' => 'Über uns (Kopie)'], $this->stored('page', $copy)->get('title'), 'the suffix in each language');
        $this->assertSame(['en' => 'About', 'de' => 'Über uns'], $this->stored('page', 'p1')->get('title'), 'the record keeps its title');

        // A copy of the copy counts on instead of doubling the suffix.
        $again = $this->copyOf($copy);
        $this->assertSame(['en' => 'About (copy 2)', 'de' => 'Über uns (Kopie 2)'], $this->stored('page', $again)->get('title'));
        $this->assertSame(['en' => 'About (copy 3)', 'de' => 'Über uns (Kopie 3)'], $this->stored('page', $this->copyOf($again))->get('title'));
    }

    #[RunInSeparateProcess]
    public function test_only_languages_with_a_title_get_the_suffix(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string', 'translatable' => true]],
        ]));
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['title' => ['en' => 'About'], '_locales' => ['en']]);

        $this->assertSame(['en' => 'About (copy)'], $this->stored('page', $this->copyOf('p1'))->get('title'));
    }

    private function copyOf(string $id): string
    {
        $res = $this->send('POST', '/folio/page/' . $id . '/duplicate');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame(1, preg_match('#^/folio/page/([0-9a-f]{32})/edit$#', $res->getHeaderLine('Location'), $m));

        return $m[1];
    }

    #[RunInSeparateProcess]
    public function test_without_the_csrf_token_nothing_is_copied(): void
    {
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['title' => 'About']);
        $this->token = 'not-the-token';

        $res = $this->send('POST', '/folio/page/p1/duplicate');

        $this->assertNotSame(302, $res->getStatusCode());
        $this->assertSame(['p1'], $this->ids('page'));
    }

    #[RunInSeparateProcess]
    public function test_403_without_the_create_permission(): void
    {
        $this->setUpStaff();
        $this->as('editor');
        $this->seed('page', 'p1', ['title' => 'About']);

        $this->assertSame(403, $this->send('POST', '/folio/page/p1/duplicate')->getStatusCode());
        $this->assertSame(['p1'], $this->ids('page'));
        $this->assertStringNotContainsString('/folio/page/p1/duplicate', (string) $this->send('GET', '/folio/page')->getBody(), 'nor in the menu');
    }

    #[RunInSeparateProcess]
    public function test_403_with_create_but_without_view(): void
    {
        // A copy shows the record: creating without seeing would read any record by its id.
        $this->install();
        $this->role('r-editor', 'staff', 'editor', ['folio.dashboard', 'page.create']);
        $this->as('editor');
        $this->seed('page', 'p1', ['title' => 'About']);

        $this->assertSame(403, $this->send('POST', '/folio/page/p1/duplicate')->getStatusCode());
        $this->assertSame(['p1'], $this->ids('page'));
    }

    #[RunInSeparateProcess]
    public function test_a_system_key_as_the_first_list_column_is_not_passed_as_an_override(): void
    {
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['_publish_at' => ['type' => 'string'], 'title' => ['type' => 'string']],
        ]));
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['_publish_at' => '2099-01-01T10:00', 'title' => 'About']);

        $copy = $this->stored('page', $this->copyOf('p1'));

        $this->assertNotNull($copy, 'the copy is made, not refused with a 404');
        $this->assertSame('About', $copy->get('title'));
    }

    #[RunInSeparateProcess]
    public function test_a_title_in_a_language_the_site_no_longer_has_is_copied_as_it_is(): void
    {
        $this->write('config/i18n.php', "<?php\nreturn ['default' => 'en', 'available' => ['en', 'de'], 'fallback' => 'en', 'url_strategy' => 'prefix'];\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string', 'translatable' => true]],
        ]));
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('page', 'p1', ['title' => ['en' => 'About', 'fr' => 'À propos'], '_locales' => ['en']]);

        $this->assertSame(['en' => 'About (copy)', 'fr' => 'À propos'], $this->stored('page', $this->copyOf('p1'))->get('title'));
    }

    #[RunInSeparateProcess]
    public function test_a_user_type_is_refused_on_the_route_and_in_the_menu(): void
    {
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('member', 'm1', ['email' => 'ada@site.test']);

        $this->assertSame(404, $this->send('POST', '/folio/member/m1/duplicate')->getStatusCode());
        $this->assertSame(['m1'], $this->ids('member'));
        $list = (string) $this->send('GET', '/folio/member')->getBody();
        $this->assertStringContainsString('data-folio-row-menu', $list);
        $this->assertStringNotContainsString('/duplicate"', $list);
    }

    #[RunInSeparateProcess]
    public function test_a_singleton_is_refused(): void
    {
        $this->setUpStaff();
        $this->as('admin');
        $this->seed('site', 'site', ['tagline' => 'Hello']);

        $this->assertSame(404, $this->send('POST', '/folio/site/site/duplicate')->getStatusCode());
        $this->assertSame(['site'], $this->ids('site'));
    }

    #[RunInSeparateProcess]
    public function test_a_missing_record_is_a_404(): void
    {
        $this->setUpStaff();
        $this->as('admin');

        $this->assertSame(404, $this->send('POST', '/folio/page/nothing/duplicate')->getStatusCode());
        $this->assertSame([], $this->ids('page'));
    }
}
