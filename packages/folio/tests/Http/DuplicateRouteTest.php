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
 * permission) makes a copy through RecordWriter::duplicate() and opens its editor with a "This is a
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
        $this->assertSame('About', $copy->get('title'));
        $this->assertNotNull($this->stored('page', 'p1'), 'the record itself stays');

        $editor = (string) $this->send('GET', $res->getHeaderLine('Location'))->getBody();
        $this->assertStringContainsString('This is a copy of “About”.', html_entity_decode($editor, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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
