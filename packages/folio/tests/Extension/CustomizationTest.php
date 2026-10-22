<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Extension;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Folio\Extension\ExtensionException;
use Preflow\Folio\Tests\Support\PublishingScaffold;
use Psr\Http\Message\ResponseInterface;

/**
 * A full app with auth and preflow/roles and a "crm" extension (written by the test)
 * customizing a plain "contact" type and the publishing "note" type: panels, a record
 * action, a list column and the save/delete hooks. The hooks log to Log::$entries.
 */
final class CustomizationTest extends TestCase
{
    use PublishingScaffold;

    private ?Application $session = null;
    private string $token = '';
    private \Closure $autoload;

    protected function setUp(): void
    {
        $this->scaffoldPublishing();
        $this->writeModel('contact', [
            'label' => 'Contacts',
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'email' => ['type' => 'string'],
                'status' => ['type' => 'string'],
            ],
        ]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ['email' => 'approver@site.test', 'password_hash' => $hash, 'roles' => ['approver']],
            ]]],
            'session' => ['cookie' => 'folio_custom_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'contact.view', 'contact.edit'],
            'approver' => ['folio.dashboard', 'contact.view', 'crm.approve'],
        ]], true) . ";\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Roles\\RolesServiceProvider::class];\n");

        $root = $this->dir . '/app/Folio/';
        $this->autoload = static function (string $class) use ($root): void {
            if (str_starts_with($class, 'App\\Folio\\')) {
                $file = $root . str_replace('\\', '/', substr($class, strlen('App\\Folio\\'))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
            }
        };
        spl_autoload_register($this->autoload);
    }

    protected function tearDown(): void
    {
        spl_autoload_unregister($this->autoload);
        $this->removeScaffold();
    }

    #[RunInSeparateProcess]
    public function test_panel_renders_on_edit_not_on_create(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@example.org']);

        $edit = (string) $this->send('GET', '/folio/contact/c1/edit')->getBody();
        $extras = substr($edit, (int) strpos($edit, 'class="folio-form-extras"'));
        $this->assertStringContainsString('class="folio-form-extras"', $edit);
        // A string panel is escaped text; a ScreenView panel is its template (given the record).
        $this->assertStringContainsString('Mail &lt;ada@example.org&gt;', $extras);
        $this->assertStringContainsString('<p class="crm-card">Card of Ada (contact)</p>', $extras);
        // "before" panels precede "after" ones.
        $this->assertLessThan(strpos($extras, 'Mail &lt;'), strpos($extras, 'crm-card'));
        $this->assertStringContainsString('Summary', $extras);

        $create = (string) $this->send('GET', '/folio/contact/new')->getBody();
        $this->assertStringNotContainsString('crm-card', $create);
        $this->assertStringNotContainsString('Mail &lt;', $create);
        $this->assertStringNotContainsString('/_action/', $create);
    }

    #[RunInSeparateProcess]
    public function test_record_action_runs_and_redirects_with_flash(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);

        $edit = (string) $this->send('GET', '/folio/contact/c1/edit')->getBody();
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/folio/contact/c1/_action/approve" data-confirm="Approve this contact\?">\s*<input type="hidden" name="_csrf_token" value="' . preg_quote($this->token, '#') . '">#',
            $edit,
        );
        $this->assertStringContainsString('#ui-check"></use></svg>Approve</button>', $edit);

        $res = $this->send('POST', '/folio/contact/c1/_action/approve');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/contact/c1/edit', $res->getHeaderLine('Location'));
        $this->assertSame('approved', $this->stored('contact', 'c1')?->get('status'));

        $after = (string) $this->send('GET', '/folio/contact/c1/edit')->getBody();
        $this->assertStringContainsString('<div class="folio-alert folio-alert-success" role="status">Approve: done</div>', $after);
        // A flash shows once.
        $this->assertStringNotContainsString('Approve: done', (string) $this->send('GET', '/folio/contact/c1/edit')->getBody());

        $this->assertSame(404, $this->send('POST', '/folio/contact/nope/_action/approve')->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/contact/c1/_action/unknown')->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_record_action_permission_denied_is_403(): void
    {
        $this->crm();
        $this->as('editor'); // contact.edit, not crm.approve
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);

        $this->assertStringNotContainsString('/_action/approve', (string) $this->send('GET', '/folio/contact/c1/edit')->getBody());
        $res = $this->send('POST', '/folio/contact/c1/_action/approve');
        $this->assertSame(403, $res->getStatusCode());
        $this->assertStringContainsString('have access to this', (string) $res->getBody());
        $this->assertSame('new', $this->stored('contact', 'c1')?->get('status'));
    }

    #[RunInSeparateProcess]
    public function test_action_button_hidden_without_type_edit(): void
    {
        $this->crm();
        $this->as('approver'); // crm.approve, not contact.edit
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);

        $edit = $this->send('GET', '/folio/contact/c1/edit');
        $this->assertSame(200, $edit->getStatusCode());
        $this->assertStringNotContainsString('/_action/approve', (string) $edit->getBody());
        $this->assertSame(403, $this->send('POST', '/folio/contact/c1/_action/approve')->getStatusCode());
        $this->assertSame('new', $this->stored('contact', 'c1')?->get('status'));
    }

    #[RunInSeparateProcess]
    public function test_record_action_needs_csrf(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);

        $res = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/contact/c1/_action/approve')->withParsedBody([]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('new', $this->stored('contact', 'c1')?->get('status'));
    }

    #[RunInSeparateProcess]
    public function test_list_column_appears_escaped(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@<b>example</b>.org']);

        $body = (string) $this->send('GET', '/folio/contact')->getBody();
        // Sorted by position: Domain (10) before Badge (100), after the type's columns.
        $this->assertMatchesRegularExpression('#<th>Status</th><th>Domain</th><th>Badge</th>#', $body);
        $this->assertStringContainsString('<td>&lt;b&gt;example&lt;/b&gt;.org</td><td>VIP</td>', $body);
    }

    #[RunInSeparateProcess]
    public function test_before_save_can_change_record_before_validation(): void
    {
        $this->crm();
        $this->as('admin');

        // name is required: the hook fills it, so validation passes.
        $res = $this->send('POST', '/folio/contact', ['name' => '', 'email' => 'ADA@Example.ORG']);
        $this->assertSame(302, $res->getStatusCode());
        $records = $this->session->container()->get(DataManager::class)->queryType('contact')->get()->items();
        $this->assertCount(1, $records);
        $record = array_values($records)[0];
        $this->assertSame('Anonymous', $record->get('name'));
        $this->assertSame('ada@example.org', $record->get('email'));

        $update = $this->send('POST', '/folio/contact/' . $record->getId(), ['name' => 'Ada', 'email' => 'X@Y.Z']);
        $this->assertSame(302, $update->getStatusCode());
        $this->assertSame('x@y.z', $this->stored('contact', (string) $record->getId())?->get('email'));
    }

    #[RunInSeparateProcess]
    public function test_before_save_validation_exception_shows_form_again(): void
    {
        $this->crm();
        $this->as('admin');

        $res = $this->send('POST', '/folio/contact', ['name' => 'Ada', 'status' => 'blocked']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Blocked contacts cannot be saved.', (string) $res->getBody());
        $this->assertSame([], $this->session->container()->get(DataManager::class)->queryType('contact')->get()->items());

        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);
        $update = $this->send('POST', '/folio/contact/c1', ['name' => 'Ada L.', 'status' => 'blocked']);
        $this->assertSame(422, $update->getStatusCode());
        $this->assertStringContainsString('Blocked contacts cannot be saved.', (string) $update->getBody());
        $this->assertSame('Ada', $this->stored('contact', 'c1')?->get('name'));
    }

    #[RunInSeparateProcess]
    public function test_after_save_sees_saved_record(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        $this->send('POST', '/folio/contact/c1', ['name' => 'Ada L.', 'email' => 'a@b.c']);

        $this->assertSame(['before:contact:Ada L.', 'after:contact:stored=Ada L.'], \App\Folio\Crm\Log::$entries);
    }

    #[RunInSeparateProcess]
    public function test_before_delete_refused_keeps_record(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'locked']);
        $this->seed('contact', 'c2', ['name' => 'Bob']);

        $res = $this->send('POST', '/folio/contact/c1/delete');
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/contact', $res->getHeaderLine('Location'));
        $this->assertNotNull($this->stored('contact', 'c1'));
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">Locked contacts stay.</div>', (string) $this->send('GET', '/folio/contact')->getBody());

        $this->assertSame(302, $this->send('POST', '/folio/contact/c2/delete')->getStatusCode());
        $this->assertNull($this->stored('contact', 'c2'));
        $this->assertContains('deleted:c2', \App\Folio\Crm\Log::$entries);
        $this->assertNotContains('deleted:c1', \App\Folio\Crm\Log::$entries);
    }

    #[RunInSeparateProcess]
    public function test_hooks_run_for_publishing_type_on_update(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('note', 'n1', ['name' => 'First']);

        $res = $this->send('POST', '/folio/note/n1', ['name' => 'Second', '_action' => 'publish']);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('Second (checked)', $this->stored('note', 'n1')?->get('name'));
        $this->assertSame('Second (checked)', $this->session->container()->get(\Preflow\Folio\Publishing\Publisher::class)->snapshot('note', 'n1')?->get('name'));
        $this->assertSame(['note-after:Second (checked)'], \App\Folio\Crm\Log::$entries);
    }

    #[RunInSeparateProcess]
    public function test_customization_for_unknown_type_throws(): void
    {
        $this->crm();
        $this->write('app/Folio/Crm/Types/Ghost.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;
            #[\Preflow\Folio\Extension\Attributes\FolioType('ghost')]
            final class Ghost {}
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('"ghost"');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_duplicate_action_name_throws(): void
    {
        $this->crm();
        // A second extension customizing contact with an action of the same name.
        $this->write('app/Folio/Zed/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Zed;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'zed', label: 'Zed')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Zed/Types/MoreContact.php', <<<'PHP'
            <?php
            namespace App\Folio\Zed\Types;
            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class MoreContact
            {
                #[\Preflow\Folio\Extension\RecordAction('approve', 'Approve again', 'crm.approve')]
                public function again(): null { return null; }
            }
            PHP);
        $this->write('config/folio/extensions.json', '{"enabled": {"crm": {"disabled_parts": []}, "zed": {"disabled_parts": []}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Record action "approve" of type "contact"');
        $this->app();
    }

    #[RunInSeparateProcess]
    public function test_disabled_type_part_has_no_effect(): void
    {
        $this->crm(['type:contact']);
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@example.org', 'status' => 'new']);

        $this->assertStringNotContainsString('<th>Domain</th>', (string) $this->send('GET', '/folio/contact')->getBody());
        $edit = (string) $this->send('GET', '/folio/contact/c1/edit')->getBody();
        $this->assertStringNotContainsString('crm-card', $edit);
        $this->assertStringNotContainsString('/_action/approve', $edit);
        $this->assertSame(404, $this->send('POST', '/folio/contact/c1/_action/approve')->getStatusCode());

        $this->send('POST', '/folio/contact/c1', ['name' => 'Ada', 'email' => 'ADA@X.Y']);
        $this->assertSame('ADA@X.Y', $this->stored('contact', 'c1')?->get('email'));
        $this->assertSame([], \App\Folio\Crm\Log::$entries);
    }

    /** The "crm" extension: ContactAdmin (contact) and NoteAdmin (note), logging to Log::$entries. */
    private function crm(array $disabled = []): void
    {
        $this->write('app/Folio/Crm/Extension.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm;
            #[\Preflow\Folio\Extension\Attributes\FolioExtension(name: 'crm', label: 'CRM')]
            final class Extension extends \Preflow\Folio\Extension\Extension {}
            PHP);
        $this->write('app/Folio/Crm/Log.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm;
            final class Log { public static array $entries = []; }
            PHP);
        $this->write('app/Folio/Crm/templates/card.twig', '<p class="crm-card">Card of {{ record.name }} ({{ type }})</p>');
        $this->write('app/Folio/Crm/Types/ContactAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;

            use App\Folio\Crm\Log;
            use Preflow\Data\DataManager;
            use Preflow\Data\DynamicRecord;
            use Preflow\Folio\Extension\Customization\Refused;
            use Preflow\Folio\Extension\Screen\ScreenView;

            #[\Preflow\Folio\Extension\Attributes\FolioType('contact')]
            final class ContactAdmin
            {
                public function __construct(private readonly DataManager $dm) {}

                #[\Preflow\Folio\Extension\FormPanel('Summary')]
                public function summary(DynamicRecord $record): string
                {
                    return 'Mail <' . $record->get('email') . '>';
                }

                #[\Preflow\Folio\Extension\FormPanel('Card', position: 'before')]
                public function card(DynamicRecord $record): ScreenView
                {
                    return new ScreenView('@crm/card.twig');
                }

                #[\Preflow\Folio\Extension\RecordAction('approve', 'Approve', 'crm.approve', icon: 'ui-check', confirm: 'Approve this contact?')]
                public function approve(DynamicRecord $record): null
                {
                    $record->set('status', 'approved');
                    $this->dm->saveType($record, false);

                    return null;
                }

                #[\Preflow\Folio\Extension\ListColumn('Badge')]
                public function badge(DynamicRecord $record): string
                {
                    return 'VIP';
                }

                #[\Preflow\Folio\Extension\ListColumn('Domain', position: 10)]
                public function domain(DynamicRecord $record): string
                {
                    return substr((string) strrchr((string) $record->get('email'), '@'), 1);
                }

                #[\Preflow\Folio\Extension\BeforeSave]
                public function normalize(DynamicRecord $record): void
                {
                    if ((string) $record->get('name') === '') {
                        $record->set('name', 'Anonymous');
                    }
                    $record->set('email', strtolower((string) $record->get('email')));
                    if ($record->get('status') === 'blocked') {
                        throw new \Preflow\Validation\ValidationException(new \Preflow\Validation\ValidationResult(['status' => ['Blocked contacts cannot be saved.']]));
                    }
                    Log::$entries[] = 'before:contact:' . $record->get('name');
                }

                #[\Preflow\Folio\Extension\AfterSave]
                public function saved(DynamicRecord $record): void
                {
                    Log::$entries[] = 'after:contact:stored=' . $this->dm->findType('contact', (string) $record->getId())?->get('name');
                }

                #[\Preflow\Folio\Extension\BeforeDelete]
                public function guard(DynamicRecord $record): void
                {
                    if ($record->get('status') === 'locked') {
                        throw new Refused('Locked contacts stay.');
                    }
                }

                #[\Preflow\Folio\Extension\AfterDelete]
                public function deleted(DynamicRecord $record): void
                {
                    Log::$entries[] = 'deleted:' . $record->getId();
                }
            }
            PHP);
        $this->write('app/Folio/Crm/Types/NoteAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;

            use App\Folio\Crm\Log;
            use Preflow\Data\DynamicRecord;

            #[\Preflow\Folio\Extension\Attributes\FolioType('note')]
            final class NoteAdmin
            {
                #[\Preflow\Folio\Extension\BeforeSave]
                public function check(DynamicRecord $record): void
                {
                    $record->set('name', $record->get('name') . ' (checked)');
                }

                #[\Preflow\Folio\Extension\AfterSave]
                public function saved(DynamicRecord $record): void
                {
                    Log::$entries[] = 'note-after:' . $record->get('name');
                }
            }
            PHP);
        $this->write('config/folio/extensions.json', (string) json_encode(['enabled' => ['crm' => ['disabled_parts' => $disabled]]]));
    }

    /**
     * Store a record directly (no hooks), in the signed-in app.
     *
     * @param array<string, mixed> $data
     */
    private function seed(string $type, string $id, array $data): void
    {
        $dm = $this->session->container()->get(DataManager::class);
        $dm->saveType(DynamicRecord::fromArray($dm->getTypeRegistry()->get($type), ['uuid' => $id] + $data), false);
    }

    private function stored(string $type, string $id): ?DynamicRecord
    {
        return $this->session->container()->get(DataManager::class)->findType($type, $id);
    }

    private function as(string $role): void
    {
        $this->session = $this->app();
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->session->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => "{$role}@site.test", 'password' => 'secret',
        ]));
        $this->assertSame(302, $login->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function send(string $method, string $uri, array $body = []): ResponseInterface
    {
        $req = (new Psr17Factory())->createServerRequest($method, $uri);
        if ($method === 'POST') {
            $req = $req->withParsedBody(['_csrf_token' => $this->token] + $body);
        }

        return $this->session->handle($req);
    }
}
