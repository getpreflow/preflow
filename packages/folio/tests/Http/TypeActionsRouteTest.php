<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Tests\Rbac\RbacAppScaffold;

/**
 * A type's editor actions (data's "readonly": true, "creatable": false) as Folio renders and
 * refuses them: a read-only type ("faq", records seeded from files) is listed and viewed, never
 * created, changed or deleted; a type that is not creatable ("message", an inbox) has no "New"
 * but is changed (its flags) and deleted. Staff "admin" has every permission: the refusals come
 * from the type, not from a role.
 */
final class TypeActionsRouteTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
        $this->write('config/models/faq.json', (string) json_encode([
            'key' => 'faq', 'table' => 'faq', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'FAQ', 'readonly' => true,
            'list' => ['columns' => ['question', 'sort']],
            'fields' => [
                'question' => ['type' => 'string', 'label' => 'Question'],
                'answer' => ['type' => 'text', 'label' => 'Answer'],
                'category' => ['type' => 'select', 'label' => 'Category', 'options' => ['start' => 'Getting started', 'buying' => 'Buying']],
                'sort' => ['type' => 'number', 'label' => 'Order'],
            ],
        ]));
        $this->write('config/models/message.json', (string) json_encode([
            'key' => 'message', 'table' => 'message', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Inbox', 'creatable' => false,
            'list' => ['columns' => ['body', 'is_read'], 'bulk' => ['is_read']],
            'fields' => [
                'body' => ['type' => 'text', 'label' => 'Message', 'readonly' => true],
                'is_read' => ['type' => 'boolean', 'label' => 'Read'],
            ],
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    /** @param array<string, mixed> $data */
    private function seed(string $type, string $id, array $data): void
    {
        $dm = $this->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data), false);
    }

    private function seedFaq(): void
    {
        $this->seed('faq', 'f1', ['question' => 'Is there a trial?', 'answer' => "No trial.\nBuy once, keep it.", 'category' => 'buying', 'sort' => 1]);
    }

    private function body(\Psr\Http\Message\ResponseInterface $res): string
    {
        return html_entity_decode((string) $res->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    #[RunInSeparateProcess]
    public function test_a_read_only_type_is_listed_and_viewed_without_new_save_or_delete(): void
    {
        $this->install();
        $this->as('admin');
        $this->seedFaq();

        $list = $this->send('GET', '/folio/faq');
        $this->assertSame(200, $list->getStatusCode());
        $html = (string) $list->getBody();
        $this->assertStringContainsString('Is there a trial?', $html);
        $this->assertDoesNotMatchRegularExpression('#href="/folio/faq/new"#', $html, 'no "New" in the header or the empty state');
        $this->assertStringNotContainsString('data-folio-new', $html);
        $this->assertStringContainsString('class="folio-badge is-readonly"', $html, 'the list says it is read-only');

        $edit = $this->send('GET', '/folio/faq/f1/edit');
        $this->assertSame(200, $edit->getStatusCode());
        $page = $this->body($edit);
        $this->assertStringContainsString('class="folio-notice folio-readonly-note"', $page, 'a note says why it cannot be edited');
        $this->assertStringContainsString('FAQ is kept outside this admin', $page);
        $this->assertStringContainsString('class="folio-badge is-readonly"', $page, 'a badge beside the title');
        $this->assertStringNotContainsString('data-folio-save', $page, 'no Save');
        $this->assertStringNotContainsString('/folio/faq/f1/delete', $page, 'no Delete');
        $this->assertStringNotContainsString('name="question"', $page, 'no inputs');
        $this->assertStringContainsString('name="_csrf_token"', $page, 'the page still carries the token (a site test posts with it and is refused)');
        // The fields are the page's content, in the main column, not hidden under "System fields".
        $this->assertMatchesRegularExpression('#<div class="form-group folio-readonly is-content">\s*<span class="form-label">Question</span>\s*<div class="folio-readonly-value">Is there a trial\?</div>#', $page);
        $this->assertStringContainsString('Buying', $page, 'a select shows its option label');
        $this->assertStringContainsString("No trial.\nBuy once, keep it.", $page, 'line breaks of a text are kept');
        preg_match('#<dl class="folio-system-list">(.*?)</dl>#s', $page, $system);
        $this->assertStringNotContainsString('Question', $system[1] ?? '', 'System fields keep the record id alone');

        // The dashboard and the ⌘K palette offer no "New FAQ" either; "New page" stays.
        $dashboard = (string) $this->send('GET', '/folio')->getBody();
        $this->assertStringNotContainsString('/folio/faq/new', $dashboard);
        preg_match('#<script type="application/json" id="folio-commands"[^>]*>(.*?)</script>#s', $dashboard, $commands);
        $urls = array_column((array) (json_decode($commands[1] ?? '', true)['actions'] ?? []), 'url');
        $this->assertNotContains('/folio/faq/new', $urls);
        $this->assertContains('/folio/page/new', $urls);
    }

    #[RunInSeparateProcess]
    public function test_a_read_only_type_refuses_create_update_and_delete_with_a_styled_403(): void
    {
        $this->install();
        $this->as('admin');
        $this->seedFaq();

        $form = $this->send('GET', '/folio/faq/new');
        $this->assertSame(403, $form->getStatusCode(), 'the create form opens');
        $this->assertStringContainsString('folio-ink-error', (string) $form->getBody(), "Folio's styled page");
        $this->assertStringContainsString('FAQ is read-only', $this->body($form), 'it says why');
        $this->assertMatchesRegularExpression('#<a href="/folio/faq">Back to FAQ</a>#', $this->body($form), 'and the way back');
        $this->assertStringNotContainsString('Ask an administrator', $this->body($form), 'no role can lift it');

        $stored = $this->stored('faq', 'f1');
        $this->assertNotNull($stored, 'the record stays');
        $this->assertSame('Is there a trial?', $stored->get('question'), 'unchanged');
        $this->assertCount(1, $this->container()->get(DataManager::class)->queryType('faq')->get()->items(), 'nothing created');
    }

    #[RunInSeparateProcess]
    public function test_a_type_that_is_not_creatable_has_no_new_but_edits_and_deletes(): void
    {
        $this->install();
        $this->as('admin');
        $this->seed('message', 'm1', ['body' => 'Hello there, a visitor.', 'is_read' => false]);
        $this->seed('message', 'm2', ['body' => 'Another one.', 'is_read' => false]);

        $list = (string) $this->send('GET', '/folio/message')->getBody();
        $this->assertDoesNotMatchRegularExpression('#href="/folio/message/new"#', $list);
        $this->assertStringNotContainsString('is-readonly', $list, 'not read-only');
        $this->assertSame(403, $this->send('GET', '/folio/message/new')->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/message', ['body' => 'Written by staff.'])->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/message/m1/duplicate')->getStatusCode());
        $this->assertCount(2, $this->container()->get(DataManager::class)->queryType('message')->get()->items(), 'no new message');

        $edit = $this->body($this->send('GET', '/folio/message/m1/edit'));
        $this->assertStringContainsString('data-folio-save', $edit, 'its flags are saved');
        $this->assertStringContainsString('/folio/message/m1/delete', $edit, 'it can be deleted');
        $this->assertStringNotContainsString('folio-readonly-note', $edit);
        // The visitor's message (a per-field "readonly" field) is the record's content: the main column, not System fields.
        $this->assertMatchesRegularExpression('#<div class="form-group folio-readonly is-content">\s*<span class="form-label">Message</span>\s*<div class="folio-readonly-value">Hello there, a visitor.</div>#', $edit);
        preg_match('#<dl class="folio-system-list">(.*?)</dl>#s', $edit, $system);
        $this->assertStringNotContainsString('Hello there', $system[1] ?? '');

        $save = $this->send('POST', '/folio/message/m1', ['is_read' => '1']);
        $this->assertSame(302, $save->getStatusCode(), 'an edit of its flags');
        $this->assertTrue((bool) $this->stored('message', 'm1')->get('is_read'));

        $this->assertSame(302, $this->send('POST', '/folio/message/m2/delete')->getStatusCode());
        $this->assertNull($this->stored('message', 'm2'));
    }

    #[RunInSeparateProcess]
    public function test_the_roles_matrix_offers_only_the_verbs_a_type_allows(): void
    {
        $this->install();
        $this->as('admin');
        $roles = array_values(array_filter($this->roles(), static fn (DynamicRecord $r): bool => $r->get('population') === 'staff'));
        $this->assertNotEmpty($roles);

        $page = $this->body($this->send('GET', '/folio/rbac_role/' . rawurlencode((string) $roles[0]->getId()) . '/edit'));

        $this->assertStringContainsString('aria-label="FAQ: View"', $page);
        foreach (['Create', 'Edit', 'Publish', 'Delete'] as $verb) {
            $this->assertStringNotContainsString('aria-label="FAQ: ' . $verb . '"', $page, "FAQ: {$verb}");
        }
        $this->assertStringNotContainsString('aria-label="Inbox: Create"', $page);
        $this->assertStringContainsString('aria-label="Inbox: Edit"', $page);
        $this->assertStringContainsString('aria-label="Inbox: Delete"', $page);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> every POST route of a type, on the read-only "faq" */
    public static function writeRoutes(): iterable
    {
        yield 'create' => ['/folio/faq', ['question' => 'x']];
        yield 'preview of a new record' => ['/folio/faq/preview', ['question' => 'x']];
        yield 'update' => ['/folio/faq/f1', ['question' => 'x']];
        yield 'preview' => ['/folio/faq/f1/preview', ['question' => 'x']];
        yield 'delete' => ['/folio/faq/f1/delete', []];
        yield 'delete plan' => ['/folio/faq/_delete-plan', ['ids' => ['f1']]];
        yield 'bulk delete' => ['/folio/faq/_bulk/delete', ['ids' => ['f1']]];
        yield 'bulk action' => ['/folio/faq/_bulk/set-category', ['ids' => ['f1'], 'value' => 'start']];
        yield 'record action' => ['/folio/faq/f1/_action/anything', []];
        yield 'duplicate' => ['/folio/faq/f1/duplicate', []];
        yield 'move' => ['/folio/faq/f1/move', ['direction' => 'up']];
        yield 'confirm a translation' => ['/folio/faq/f1/_translations/confirm', ['_confirm_path' => 'question']];
        yield 'autosave' => ['/folio/faq/f1/autosave', ['question' => 'x']];
        yield 'discard' => ['/folio/faq/f1/discard', []];
        yield 'unpublish' => ['/folio/faq/f1/unpublish', []];
        yield 'restore a revision' => ['/folio/faq/f1/revisions/r1/restore', []];
        yield 'share' => ['/folio/faq/f1/share', []];
        yield 'revoke a share' => ['/folio/faq/f1/share/revoke', []];
    }

    /** @param array<string, mixed> $body */
    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('writeRoutes')]
    public function test_every_write_route_of_a_read_only_type_answers_403(string $path, array $body): void
    {
        $this->install();
        $this->as('admin');
        $this->seedFaq();

        $res = $this->send('POST', $path, $body);

        $this->assertSame(403, $res->getStatusCode(), $path);
        $this->assertSame('Is there a trial?', $this->stored('faq', 'f1')?->get('question'));
        $this->assertSame('buying', $this->stored('faq', 'f1')?->get('category'));
        $this->assertCount(1, $this->container()->get(DataManager::class)->queryType('faq')->get()->items());
    }

    /**
     * Every {type} route Folio has (AI, publishing and passwords on too): each carries the type
     * middleware, and each that writes is refused for the read-only type (the AI run, its jobs,
     * translate and the rest included); reads pass. The middleware runs as the kernel runs it.
     */
    #[RunInSeparateProcess]
    public function test_every_type_route_that_writes_is_refused_by_the_middleware(): void
    {
        $this->install();
        $middleware = $this->container()->get(\Preflow\Folio\Http\TypeActionsMiddleware::class);
        $handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return new \Nyholm\Psr7\Response(200);
            }
        };
        $checked = [];
        foreach (\Preflow\Folio\Routing\FolioRoutes::admin('/folio', true, true, true, true, true) as $entry) {
            if (!in_array('type', $entry->paramNames, true)) {
                continue;
            }
            $this->assertContains(\Preflow\Folio\Http\TypeActionsMiddleware::class, $entry->middleware, $entry->pattern);
            $rule = \Preflow\Folio\Http\FolioPermissionMiddleware::rule($entry->handler);
            $params = array_fill_keys($entry->paramNames, 'x');
            $params['type'] = 'faq';
            $route = new \Preflow\Core\Routing\Route(\Preflow\Core\Routing\RouteMode::Action, $entry->handler, $params);
            $request = (new \Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest($entry->method, '/x')->withAttribute(\Preflow\Core\Routing\Route::class, $route);
            $expected = $rule === '{type}.view' ? 200 : 403;
            $this->assertSame($expected, $middleware->process($request, $handler)->getStatusCode(), "{$entry->method} {$entry->pattern} ({$rule})");
            $checked[] = $entry->method . ' ' . $entry->pattern;
        }
        foreach (['POST /folio/{type}/_ai/run', 'POST /folio/{type}/_ai/jobs/{job}/step', 'POST /folio/{type}/{id}/_ai/suggest', 'POST /folio/{type}/{id}/autosave', 'POST /folio/{type}/{id}/revisions/{rev}/restore', 'POST /folio/{type}/_bulk/{name}', 'POST /folio/{type}/{id}/_action/{name}'] as $route) {
            $this->assertContains($route, $checked);
        }
    }

    /** Templates (an extension's screen, a site page) ask folio_can(): the permission and what the type allows. */
    #[RunInSeparateProcess]
    public function test_folio_can_composes_the_permission_and_the_type(): void
    {
        $this->write('app/pages/can.twig', "{% for p in ['page.create', 'faq.view', 'faq.create', 'faq.edit', 'message.create', 'message.edit'] %}{{ p }}={{ folio_can(p) ? 'yes' : 'no' }} {% endfor %}");
        $this->install();
        $this->as('admin');

        $this->assertSame(
            'page.create=yes faq.view=yes faq.create=no faq.edit=no message.create=no message.edit=yes',
            trim((string) $this->send('GET', '/can')->getBody()),
        );
    }

    /** A role that holds a verb the type refuses keeps it through a save of the matrix (no grant is dropped). */
    #[RunInSeparateProcess]
    public function test_a_kept_grant_survives_a_save_of_the_roles_matrix(): void
    {
        $this->install();
        $this->role('r-faq', 'staff', 'faqkeeper', ['folio.dashboard', 'faq.view', 'faq.edit', 'message.create', 'page.view']);
        $this->as('admin');

        $page = (string) $this->send('GET', '/folio/rbac_role/r-faq/edit')->getBody();
        $this->assertStringContainsString('value="faq.edit" data-rbac-kept', $page, 'kept as a hidden input');
        $permissions = [];
        preg_match_all('#<input type="(?:checkbox|hidden)" name="permissions\[\]" value="([^"]*)"([^>]*)>#', $page, $inputs, PREG_SET_ORDER);
        foreach ($inputs as [$tag, $value, $attrs]) {
            if (str_starts_with($tag, '<input type="hidden"') || str_contains($attrs, ' checked')) {
                $permissions[] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $save = $this->send('POST', '/folio/rbac_role/r-faq', ['name' => 'faqkeeper', 'label' => 'Faqkeeper', 'population' => 'staff', 'permissions' => $permissions]);

        $this->assertSame(302, $save->getStatusCode(), substr((string) $save->getBody(), 0, 300));
        $held = (array) $this->stored('rbac_role', 'r-faq')?->get('permissions');
        foreach (['faq.view', 'faq.edit', 'message.create'] as $grant) {
            $this->assertContains($grant, $held, $grant);
        }
    }

    /** Folio without sign-in (no auth configured): no permission middleware, the type still refuses. */
    #[RunInSeparateProcess]
    public function test_without_auth_the_type_still_refuses(): void
    {
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class];\n");
        unlink($this->dir . '/config/auth.php');
        unlink($this->dir . '/config/folio/extensions.json');
        foreach (['member', 'team'] as $model) {
            unlink($this->dir . '/config/models/' . $model . '.json'); // their fields are rbac's
        }
        $this->seedFaq();

        $this->assertSame(200, $this->send('GET', '/folio/faq/f1/edit')->getStatusCode());
        $this->assertSame(403, $this->send('GET', '/folio/faq/new')->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/faq/f1', ['question' => 'x'])->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/faq/f1/delete')->getStatusCode());
        $this->assertSame('Is there a trial?', $this->stored('faq', 'f1')?->get('question'));
        $this->assertStringNotContainsString('/folio/faq/new', (string) $this->send('GET', '/folio/faq')->getBody());
    }
}
