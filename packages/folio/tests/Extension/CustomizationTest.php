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
                'stage' => ['type' => 'select', 'options' => ['lead' => 'Lead', 'customer' => 'Customer']],
                'vip' => ['type' => 'boolean'],
                'bio' => ['type' => 'richtext'],
            ],
            // name (a string) and email (not select/boolean) are no bulk fields: ignored.
            'list' => ['bulk' => ['stage', 'vip', 'name']],
        ]);
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => $hash, 'roles' => ['admin']],
                ['email' => 'editor@site.test', 'password_hash' => $hash, 'roles' => ['editor']],
                ['email' => 'approver@site.test', 'password_hash' => $hash, 'roles' => ['approver']],
                ['email' => 'viewer@site.test', 'password_hash' => $hash, 'roles' => ['viewer']],
                ['email' => 'remover@site.test', 'password_hash' => $hash, 'roles' => ['remover']],
            ]]],
            'session' => ['cookie' => 'folio_custom_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/roles.php', "<?php\nreturn " . var_export(['staff' => [
            'admin' => ['*'],
            'editor' => ['folio.dashboard', 'contact.view', 'contact.edit'],
            'approver' => ['folio.dashboard', 'contact.view', 'crm.approve'],
            'viewer' => ['folio.dashboard', 'contact.view'],
            'remover' => ['folio.dashboard', 'contact.view', 'contact.delete'],
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

    /** A crm panel class for $type whose methods return ScreenViews listing the given assets (name => entries). */
    private function assetPanels(string $type, string $class, array $panels): void
    {
        $this->crm();
        $this->write('app/Folio/Crm/assets/panel.js', 'console.log(1);');
        $this->write('app/Folio/Crm/assets/panel.css', 'a{}');
        $methods = '';
        foreach ($panels as $name => $entries) {
            $list = var_export($entries, true);
            $methods .= "#[\\Preflow\\Folio\\Extension\\FormPanel('{$name}')]\n"
                . "public function {$name}(\\Preflow\\Data\\DynamicRecord \$record): \\Preflow\\Folio\\Extension\\Screen\\ScreenView { return new \\Preflow\\Folio\\Extension\\Screen\\ScreenView('@crm/card.twig', [], {$list}); }\n";
        }
        $this->write("app/Folio/Crm/Types/{$class}.php", "<?php\nnamespace App\\Folio\\Crm\\Types;\n#[\\Preflow\\Folio\\Extension\\Attributes\\FolioType('{$type}')]\nfinal class {$class}\n{\n{$methods}}\n");
    }

    private function headOf(string $html): string
    {
        return substr($html, 0, (int) strpos($html, '</head>'));
    }

    #[RunInSeparateProcess]
    public function test_panel_assets_print_once_in_the_form_head(): void
    {
        $this->assetPanels('contact', 'ContactAdmin', ['first' => ['panel.js', 'panel.css'], 'second' => ['panel.js']]);
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        $head = $this->headOf((string) $this->send('GET', '/folio/contact/c1/edit')->getBody());

        $this->assertSame(1, preg_match_all('#<script src="/folio/_assets/x/crm/panel\.js\?v=[0-9a-f]{12}" defer nonce="[^"]+"></script>#', $head));
        $this->assertSame(1, substr_count($head, '/folio/_assets/x/crm/panel.js'));
        $this->assertSame(1, preg_match_all('#<link rel="stylesheet" href="/folio/_assets/x/crm/panel\.css\?v=[0-9a-f]{12}">#', $head));
        $this->assertStringNotContainsString('folio-extension-assets', $head);
    }

    #[RunInSeparateProcess]
    public function test_panel_trix_is_not_printed_twice_on_a_form_with_a_richtext_field(): void
    {
        $this->assetPanels('contact', 'ContactAdmin', ['rich' => ['@folio/trix']]);
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        $head = $this->headOf((string) $this->send('GET', '/folio/contact/c1/edit')->getBody());

        $this->assertSame(1, substr_count($head, '/folio/_assets/trix.css'));
        $this->assertSame(1, substr_count($head, '/folio/_assets/trix.js'));
    }

    #[RunInSeparateProcess]
    public function test_panel_htmx_is_not_printed_twice_on_a_publishing_type(): void
    {
        $this->assetPanels('note', 'NoteAdmin', ['live' => ['@folio/htmx.js', 'panel.js']]);
        $this->as('admin');
        $this->seed('note', 'n1', ['name' => 'Hi']);

        $head = $this->headOf((string) $this->send('GET', '/folio/note/n1/edit')->getBody());

        $this->assertSame(1, substr_count($head, '/folio/_assets/htmx.js'));
        $this->assertSame(1, substr_count($head, 'name="htmx-config"'));
        $this->assertSame(1, substr_count($head, '/folio/_assets/x/crm/panel.js'));
        // Folio's own editor assets stay first.
        $this->assertLessThan(strpos($head, '/x/crm/panel.js'), strpos($head, '/folio/_assets/htmx.js'));
    }

    #[RunInSeparateProcess]
    public function test_missing_panel_asset_names_the_panel_method(): void
    {
        $this->assetPanels('contact', 'ContactAdmin', ['broken' => ['gone.js']]);
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        $res = $this->send('GET', '/folio/contact/c1/edit');

        $this->assertSame(500, $res->getStatusCode());
        $body = html_entity_decode((string) $res->getBody());
        $this->assertStringContainsString('gone.js', $body);
        $this->assertStringContainsString('ContactAdmin::broken', $body);
    }

    #[RunInSeparateProcess]
    public function test_forms_without_panel_assets_are_unchanged(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        foreach (['/folio/contact/c1/edit', '/folio/contact/new'] as $url) {
            $head = $this->headOf((string) $this->send('GET', $url)->getBody());
            $this->assertStringNotContainsString('/_assets/x/', $head);
        }
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
        $this->assertMatchesRegularExpression('#<th[^>]*>(<a[^>]*>)?Status\b.*?</th><th>Domain</th><th>Badge</th>#s', $body);
        $this->assertStringContainsString('<td>&lt;b&gt;example&lt;/b&gt;.org</td><td>VIP</td>', $body);
    }

    #[RunInSeparateProcess]
    public function test_field_placeholder_shows_what_an_empty_field_means_in_the_edited_locale(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada']);

        $en = (string) $this->send('GET', '/folio/contact/c1/edit')->getBody();
        $this->assertStringContainsString('name="status"', $en);
        $this->assertMatchesRegularExpression('~<input[^>]*name="status"[^>]*placeholder="Standard: new &lt;lead&gt;"~', $en);
        $this->assertDoesNotMatchRegularExpression('~<input[^>]*name="name"[^>]*placeholder=~', $en, 'only the field it names');
        $this->assertMatchesRegularExpression('~<input[^>]*name="status"[^>]*placeholder="Standard: neu"~', (string) $this->send('GET', '/folio/contact/c1/edit?locale=de')->getBody());
        $this->assertMatchesRegularExpression('~<input[^>]*name="status"[^>]*placeholder="Standard: new &lt;lead&gt;"~', (string) $this->send('GET', '/folio/contact/new')->getBody(), 'on create too');
    }

    #[RunInSeparateProcess]
    public function test_field_placeholder_for_an_unknown_field_throws(): void
    {
        $this->crm();
        $this->write('app/Folio/Crm/Types/NoteAdmin.php', <<<'PHP'
            <?php
            namespace App\Folio\Crm\Types;
            #[\Preflow\Folio\Extension\Attributes\FolioType('note')]
            final class NoteAdmin
            {
                #[\Preflow\Folio\Extension\FieldPlaceholder('nothing')]
                public function standard(string $locale): ?string { return null; }
            }
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('#[FieldPlaceholder] on App\\Folio\\Crm\\Types\\NoteAdmin::standard: type "note" has no field "nothing".');
        $this->app();
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

    #[RunInSeparateProcess]
    public function test_bulk_set_field_saves_each_record_and_reports_the_ones_that_fail(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@x.org', 'stage' => 'lead']);
        $this->seed('contact', 'c2', ['name' => 'Bob', 'email' => 'bob@x.org', 'stage' => 'lead']);
        $this->seed('contact', 'c3', ['name' => 'Cy', 'email' => '', 'stage' => 'lead']);

        $res = $this->send('POST', '/folio/contact/_bulk/set-stage', [
            'ids' => ['c1', 'c2', 'c3', 'nope'], 'value' => 'customer', '_query' => 'q=x&sort=-name&page=2&bogus=1',
        ]);
        $this->assertSame(302, $res->getStatusCode());
        // Back to the same view: only the list's own parameters, re-read and rebuilt.
        $this->assertSame('/folio/contact?q=x&sort=-name&page=2', $res->getHeaderLine('Location'));
        $this->assertSame('customer', $this->stored('contact', 'c1')?->get('stage'));
        $this->assertSame('customer', $this->stored('contact', 'c2')?->get('stage'));
        $this->assertSame('lead', $this->stored('contact', 'c3')?->get('stage'), 'refused by its BeforeSave');
        // The form's save path: BeforeSave (lower-cases the email), then AfterSave, per saved record.
        $this->assertSame(['before:contact:Ada', 'after:contact:stored=Ada', 'before:contact:Bob', 'after:contact:stored=Bob'], \App\Folio\Crm\Log::$entries);

        $list = (string) $this->send('GET', '/folio/contact')->getBody();
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">Set Stage: done (2). 1 failed: Cy — Customers need an email.</div>', $list);

        $this->send('POST', '/folio/contact/_bulk/set-vip', ['ids' => ['c1'], 'value' => '1']);
        $this->assertTrue((new \Preflow\Folio\Field\Types\BooleanFieldType())->fromStorage($this->stored('contact', 'c1')?->get('vip')));
        $this->assertStringContainsString('<div class="folio-alert folio-alert-success" role="status">Set Vip: done (1)</div>', (string) $this->send('GET', '/folio/contact')->getBody());

        // Only the select's options, '1'/'0' for a boolean; only bulk fields that are select or boolean.
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1'], 'value' => 'boss'])->getStatusCode());
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/set-vip', ['ids' => ['c1'], 'value' => 'yes'])->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/contact/_bulk/set-name', ['ids' => ['c1'], 'value' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/contact/_bulk/set-email', ['ids' => ['c1'], 'value' => 'x'])->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/contact/_bulk/unknown', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame(404, $this->send('POST', '/folio/ghost/_bulk/delete', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame('customer', $this->stored('contact', 'c1')?->get('stage'));
    }

    #[RunInSeparateProcess]
    public function test_bulk_set_and_bulk_actions_change_freshly_read_records(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@x.org', 'status' => 'new', 'stage' => 'lead']);
        $this->send('GET', '/folio/contact'); // builds the query cache
        $cacheFile = $this->dir . '/storage/cache/data/json-contact.json';
        $this->assertFileExists($cacheFile);
        // A query cache that lags behind the record file (as left by a racing reader).
        file_put_contents($cacheFile, str_replace('"Ada"', '"Stale"', (string) file_get_contents($cacheFile)));

        $this->assertSame(302, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1'], 'value' => 'customer'])->getStatusCode());
        $this->assertSame('customer', $this->stored('contact', 'c1')?->get('stage'));
        $this->assertSame('Ada', $this->stored('contact', 'c1')?->get('name'), 'saved from the record file, not the cache');

        $this->send('GET', '/folio/contact');
        file_put_contents($cacheFile, str_replace('"Ada"', '"Stale"', (string) file_get_contents($cacheFile)));
        $this->assertSame(302, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame('tagged', $this->stored('contact', 'c1')?->get('status'));
        $this->assertSame('Ada', $this->stored('contact', 'c1')?->get('name'));
    }

    #[RunInSeparateProcess]
    public function test_bulk_ids_with_a_path_never_reach_another_types_records(): void
    {
        $this->crm();
        $this->as('editor');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'stage' => 'lead']);
        $data = $this->dir . '/storage/data';
        @mkdir($data . '/staff', 0777, true);
        $staff = json_encode(['uuid' => 's1', 'name' => 'Root', 'stage' => 'lead', 'password_hash' => '$2y$10$secrethashsecrethash']);
        file_put_contents($data . '/staff/s1.json', $staff);
        $contacts = static fn (): array => array_values(array_diff(scandir($data . '/contact') ?: [], ['.', '..']));
        $before = $contacts();
        \App\Folio\Crm\Log::$entries = [];

        $unsafe = ['../staff/s1', '..\\staff\\s1', "s1\0", '..', "\xFF"];
        foreach ($unsafe as $id) {
            $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => [$id], 'value' => 'customer'])->getStatusCode(), $id);
        }
        $this->as('admin'); // the "tag" bulk action asks crm.approve
        foreach ($unsafe as $id) {
            $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => [$id]])->getStatusCode(), $id);
        }
        $this->assertSame(422, $this->send('POST', '/folio/contact/_delete-plan', ['ids' => ['../staff/s1']])->getStatusCode());

        $this->assertSame($staff, file_get_contents($data . '/staff/s1.json'), 'the staff record is untouched');
        $this->assertSame($before, $contacts(), 'no foreign record is written into the contact folder');
        $this->assertSame([], \App\Folio\Crm\Log::$entries, 'the bulk action never received a foreign record');
        $this->assertSame('lead', $this->stored('contact', 'c1')?->get('stage'));
    }

    #[RunInSeparateProcess]
    public function test_bulk_set_field_on_a_publishing_type_changes_the_working_copy(): void
    {
        $this->writeModel('note', [
            'label' => 'Notes',
            'publishing' => true,
            'fields' => [
                'name' => ['type' => 'string', 'validate' => ['required']],
                'mood' => ['type' => 'select', 'options' => ['calm', 'busy']],
            ],
            'list' => ['bulk' => ['mood']],
        ]);
        $this->crm();
        $this->as('admin');
        $this->seed('note', 'n1', ['name' => 'First', 'mood' => 'calm']);
        $this->send('POST', '/folio/note/n1', ['name' => 'First', 'mood' => 'calm', '_action' => 'publish']);
        \App\Folio\Crm\Log::$entries = [];

        $this->assertSame(302, $this->send('POST', '/folio/note/_bulk/set-mood', ['ids' => ['n1'], 'value' => 'busy'])->getStatusCode());

        $this->assertSame('busy', $this->stored('note', 'n1')?->get('mood'));
        $this->assertSame('First (checked) (checked)', $this->stored('note', 'n1')?->get('name'));
        $this->assertSame('calm', $this->session->container()->get(\Preflow\Folio\Publishing\Publisher::class)->snapshot('note', 'n1')?->get('mood'));
        $this->assertSame(['note-after:First (checked) (checked)'], \App\Folio\Crm\Log::$entries);
    }

    #[RunInSeparateProcess]
    public function test_bulk_extension_action_receives_the_records(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'new']);
        $this->seed('contact', 'c2', ['name' => 'Bob', 'status' => 'new']);
        $this->seed('note', 'n1', ['name' => 'A note']);

        $res = $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => ['c2', 'n1', 'c1', 'nope', 'c2']]);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/folio/contact', $res->getHeaderLine('Location'));
        // Records of this type only, each once, in the order posted.
        $this->assertSame(['tag:c2', 'tag:c1'], \App\Folio\Crm\Log::$entries);
        $this->assertSame('tagged', $this->stored('contact', 'c1')?->get('status'));
        $this->assertStringContainsString('<div class="folio-alert folio-alert-success" role="status">Tag: done (2)</div>', (string) $this->send('GET', '/folio/contact')->getBody());

        // Nothing (left) to act on, or too many ids.
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => ['nope']])->getStatusCode());
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/tag', [])->getStatusCode());
        $many = array_map(static fn (int $i): string => 'x' . $i, range(1, 501));
        $this->assertSame(422, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => $many])->getStatusCode());
    }

    #[RunInSeparateProcess]
    public function test_bulk_delete_goes_through_the_delete_plan(): void
    {
        $this->crm();
        $this->as('admin');
        $this->seed('contact', 'c1', ['name' => 'Ada', 'status' => 'locked']);
        $this->seed('contact', 'c2', ['name' => 'Bob']);
        $this->seed('contact', 'c3', ['name' => 'Cy']);

        // One BeforeDelete refuses: nothing is deleted, the reason is shown.
        $res = $this->send('POST', '/folio/contact/_bulk/delete', ['ids' => ['c1', 'c2'], '_query' => 'q=b']);
        $this->assertSame('/folio/contact?q=b', $res->getHeaderLine('Location'));
        $this->assertNotNull($this->stored('contact', 'c2'));
        $this->assertStringContainsString('<div class="folio-alert folio-alert-error" role="alert">Locked contacts stay.</div>', (string) $this->send('GET', '/folio/contact')->getBody());

        $this->send('POST', '/folio/contact/_bulk/delete', ['ids' => ['c2', 'c3', 'nope']]);
        $this->assertNull($this->stored('contact', 'c2'));
        $this->assertNull($this->stored('contact', 'c3'));
        $this->assertSame(['deleted:c2', 'deleted:c3'], \App\Folio\Crm\Log::$entries);
        $this->assertStringContainsString('<div class="folio-alert folio-alert-success" role="status">Delete: done (2)</div>', (string) $this->send('GET', '/folio/contact')->getBody());
    }

    #[RunInSeparateProcess]
    public function test_bulk_permissions(): void
    {
        $this->crm();
        $this->seedAs('editor'); // contact.edit, not contact.delete or crm.approve
        $this->assertSame(403, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/contact/_bulk/delete', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame(302, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1'], 'value' => 'customer'])->getStatusCode());
        $this->assertNotNull($this->stored('contact', 'c1'));
        $this->assertSame('new', $this->stored('contact', 'c1')?->get('status'));

        $this->seedAs('approver'); // crm.approve, not contact.edit
        $this->assertSame(403, $this->send('POST', '/folio/contact/_bulk/tag', ['ids' => ['c1']])->getStatusCode());
        $this->assertSame(403, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1'], 'value' => 'lead'])->getStatusCode());
        $this->assertSame('customer', $this->stored('contact', 'c1')?->get('stage'));

        $this->seedAs('remover'); // contact.delete only
        $this->assertSame(403, $this->send('POST', '/folio/contact/_bulk/set-stage', ['ids' => ['c1'], 'value' => 'lead'])->getStatusCode());
        $this->assertSame(302, $this->send('POST', '/folio/contact/_bulk/delete', ['ids' => ['c1']])->getStatusCode());
        $this->assertNull($this->stored('contact', 'c1'));

        // CSRF like every POST.
        $this->seedAs('admin');
        $res = $this->session->handle((new Psr17Factory())->createServerRequest('POST', '/folio/contact/_bulk/delete')->withParsedBody(['ids' => ['c1']]));
        $this->assertSame(403, $res->getStatusCode());
        $this->assertNotNull($this->stored('contact', 'c1'));
    }

    #[RunInSeparateProcess]
    public function test_list_shows_the_tick_column_and_the_bulk_bar_for_what_the_user_may_do(): void
    {
        $this->crm();
        $this->seedAs('admin');
        $body = (string) $this->send('GET', '/folio/contact?q=ad')->getBody();
        $bar = substr($body, (int) strpos($body, 'data-folio-bulk'));
        $this->assertStringContainsString('<form class="folio-bulk-bar" id="folio-bulk" method="post" data-folio-bulk>', $body);
        $this->assertStringContainsString('<input type="hidden" name="_query" value="q=ad">', $bar);
        $this->assertStringContainsString('<span class="folio-bulk-count" data-folio-bulk-count>With selected:</span>', $bar);
        $this->assertMatchesRegularExpression('#<summary class="btn btn-sm">Set Stage ▾</summary>#', $bar);
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm" formaction="/folio/contact/_bulk/set-stage" name="value" value="customer">Customer</button>', $bar);
        $this->assertStringContainsString('formaction="/folio/contact/_bulk/set-vip" name="value" value="0">No</button>', $bar);
        $this->assertStringNotContainsString('set-name', $bar);
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm" formaction="/folio/contact/_bulk/tag" data-confirm="Tag these contacts?">Tag</button>', $bar);
        $this->assertStringContainsString('formaction="/folio/contact/_bulk/delete" data-delete-plan="/folio/contact/_delete-plan" data-confirm="Delete the selected records?">Delete</button>', $bar);
        $this->assertStringContainsString('<button type="reset" class="btn btn-sm folio-bulk-clear" data-folio-bulk-clear>Clear</button>', $bar);
        $this->assertStringContainsString('<input type="checkbox" data-folio-bulk-all aria-label="Select all on this page" hidden>', $body);
        // Export: the header link takes the whole filtered list, the bar's button the ticked rows (JS adds the ids).
        $this->assertStringContainsString('<a class="btn btn-secondary" href="/folio/contact/_export?q=ad" data-folio-export-all="/folio/contact/_export">Export</a>', $body);
        $this->assertStringContainsString('<button type="button" class="btn btn-sm" data-folio-export="/folio/contact/_export?q=ad" hidden>Export</button>', $bar);
        $this->assertStringContainsString('<input type="checkbox" name="ids[]" value="c1" form="folio-bulk" data-folio-bulk-item aria-label="Select Ada">', $body);

        // The partial (search as you type) carries the bar too, so a swap starts a fresh selection.
        $partial = (string) $this->send('GET', '/folio/contact?q=ad&_partial=1')->getBody();
        $this->assertStringContainsString('data-folio-bulk>', $partial);
        $this->assertStringContainsString('value="c1" form="folio-bulk"', $partial);

        // Editor: set fields only (no delete, no Tag without crm.approve).
        $this->seedAs('editor');
        $editor = (string) $this->send('GET', '/folio/contact')->getBody();
        $this->assertStringContainsString('_bulk/set-stage', $editor);
        $this->assertStringNotContainsString('_bulk/tag', $editor);
        $this->assertStringNotContainsString('formaction="/folio/contact/_bulk/delete"', $editor);

        // Remover: delete only. Viewer: no tick column, no bar.
        $this->seedAs('remover');
        $remover = (string) $this->send('GET', '/folio/contact')->getBody();
        $this->assertStringContainsString('formaction="/folio/contact/_bulk/delete"', $remover);
        $this->assertStringNotContainsString('_bulk/set-', $remover);
        $this->seedAs('viewer');
        $viewer = (string) $this->send('GET', '/folio/contact')->getBody();
        $this->assertStringNotContainsString('data-folio-bulk', $viewer);
        $this->assertStringNotContainsString('name="ids[]"', $viewer);
    }

    #[RunInSeparateProcess]
    public function test_duplicate_bulk_action_name_throws(): void
    {
        $this->crm();
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
                #[\Preflow\Folio\Extension\BulkAction('tag', 'Tag again', 'crm.approve')]
                public function again(array $records): null { return null; }
            }
            PHP);
        $this->write('config/folio/extensions.json', '{"enabled": {"crm": {"disabled_parts": []}, "zed": {"disabled_parts": []}}}');

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage('Bulk action "tag" of type "contact" is declared by both App\\Folio\\Crm\\Types\\ContactAdmin::tag and App\\Folio\\Zed\\Types\\MoreContact::again.');
        $this->app();
    }

    /** @return array<string, array{string, string, string}> */
    public static function badBulkActions(): array
    {
        return [
            'delete is Folio\'s' => ['delete', 'crm.approve', 'is reserved'],
            'export is Folio\'s' => ['export', 'crm.approve', 'is reserved'],
            'set-… is Folio\'s' => ['set-stage', 'crm.approve', 'is reserved'],
            'bad name' => ['Bad Name', 'crm.approve', 'must match'],
            'bad permission' => ['ok', 'nodot', 'permission "nodot"'],
        ];
    }

    #[RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('badBulkActions')]
    public function test_bulk_action_names_and_permissions_are_validated(string $name, string $permission, string $message): void
    {
        $this->crm();
        $this->write('app/Folio/Crm/Types/NoteAdmin.php', <<<PHP
            <?php
            namespace App\\Folio\\Crm\\Types;
            #[\\Preflow\\Folio\\Extension\\Attributes\\FolioType('note')]
            final class NoteAdmin
            {
                #[\\Preflow\\Folio\\Extension\\BulkAction('{$name}', 'X', '{$permission}')]
                public function x(array \$records): null { return null; }
            }
            PHP);

        $this->expectException(ExtensionException::class);
        $this->expectExceptionMessage($message);
        $this->app();
    }

    /** Sign in as $role with c1 (Ada, new, lead) stored. */
    private function seedAs(string $role): void
    {
        $this->as($role);
        if ($this->stored('contact', 'c1') === null) {
            $this->seed('contact', 'c1', ['name' => 'Ada', 'email' => 'ada@x.org', 'status' => 'new', 'stage' => 'lead']);
        }
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

                /** @param list<DynamicRecord> $records */
                #[\Preflow\Folio\Extension\BulkAction('tag', 'Tag', 'crm.approve', confirm: 'Tag these contacts?')]
                public function tag(array $records, \Psr\Http\Message\ServerRequestInterface $request): null
                {
                    foreach ($records as $record) {
                        Log::$entries[] = 'tag:' . $record->getId();
                        $record->set('status', 'tagged');
                        $this->dm->saveType($record, false);
                    }

                    return null;
                }

                #[\Preflow\Folio\Extension\FieldPlaceholder('status')]
                public function statusStandard(string $locale): ?string
                {
                    return $locale === 'de' ? 'Standard: neu' : 'Standard: new <lead>';
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
                    if ($record->get('stage') === 'customer' && (string) $record->get('email') === '') {
                        throw new \Preflow\Validation\ValidationException(new \Preflow\Validation\ValidationResult(['email' => ['Customers need an email.']]));
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
