<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Codes\Codes;
use Preflow\Core\Application;
use Preflow\Core\Throttle\Throttle;
use Preflow\Data\DataManager;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\MigrationRegistry;
use Preflow\Data\Migration\Migrator;
use Psr\Http\Message\ResponseInterface;

/**
 * A temp app with Folio, staff auth (admin) and the codes extension enabled (a fake
 * vendor/composer/installed.json lists the package): the codes alias on SQLite, two purposes.
 */
final class CodesInFolioTest extends TestCase
{
    private string $dir;
    private ?Application $session = null;
    private string $token = '';
    private string|false $errorLog = false;

    protected function setUp(): void
    {
        putenv('APP_DEBUG=1');
        $this->dir = sys_get_temp_dir() . '/codes_folio_' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/storage/data', 0777, true);
        $this->errorLog = ini_set('error_log', $this->dir . '/error.log'); // stderr fails a separate-process test
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Codes', 'debug' => 1, 'engine' => 'twig', 'key' => 'codes-app-test-key'];\n");
        $this->write('config/providers.php', "<?php\nreturn [\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Codes\\CodesServiceProvider::class];\n");
        $this->write('config/folio.php', "<?php\nreturn ['path' => '/folio'];\n");
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'config', 'population' => 'staff']],
            'providers' => ['config' => ['class' => \Preflow\Auth\ConfigUserProvider::class, 'users' => [
                ['email' => 'admin@site.test', 'password_hash' => password_hash('secret', PASSWORD_DEFAULT), 'roles' => ['admin']],
            ]]],
            'session' => ['cookie' => 'codes_app_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/data.php', "<?php\nreturn " . var_export([
            'default' => 'json',
            'models_path' => $this->dir . '/config/models',
            'drivers' => [
                'json' => ['driver' => \Preflow\Data\Driver\JsonFileDriver::class, 'path' => $this->dir . '/storage/data'],
                'sqlite' => ['path' => $this->dir . '/storage/codes.sqlite'],
            ],
            'aliases' => ['codes' => 'sqlite'],
        ], true) . ";\n");
        $this->write('config/codes.php', "<?php\nreturn " . var_export([
            'purposes' => [
                'members.book' => ['label' => 'Book code', 'readable' => true],
                'forcemap.round' => ['label' => 'Force Map round', 'readable' => false],
            ],
            'throttle' => ['max' => 10, 'window' => 900],
        ], true) . ";\n");
        $this->write('config/models/page.json', (string) json_encode([
            'key' => 'page', 'table' => 'page', 'storage' => 'json', 'id_field' => 'uuid', 'label' => 'Pages',
            'fields' => ['title' => ['type' => 'string']],
        ]));
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [
            ['name' => 'preflow/codes', 'extra' => ['folio' => ['extension' => 'Preflow\\Codes\\Extension']]],
        ]]));
        $this->write('config/folio/extensions.json', '{"enabled": {"codes": {"disabled_parts": []}}}');
        $this->write('app/pages/index.twig', 'HOME');
    }

    protected function tearDown(): void
    {
        putenv('APP_DEBUG');
        ini_set('error_log', (string) $this->errorLog);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($this->dir);
    }

    private function write(string $rel, string $content): void
    {
        $path = $this->dir . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** The test's one app, migrated as `preflow migrate` does (package migrations, then Folio's table sync). */
    private function app(): Application
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $app = Application::create($this->dir);
        $app->boot();
        $c = $app->container();
        $registry = $c->get(MigrationRegistry::class);
        (new Migrator($c->get(DataManager::class)->driver('codes')->pdo(), $registry->paths()))->migrate();
        foreach ($registry->steps() as $step) {
            $step();
        }

        return $this->session = $app;
    }

    private function dm(): DataManager
    {
        return $this->app()->container()->get(DataManager::class);
    }

    private function codes(): Codes
    {
        $c = $this->app()->container();

        return new Codes($c->get(DataManager::class), $c->get(Throttle::class), require $this->dir . '/config/codes.php');
    }

    private function stored(string $id): DynamicRecord
    {
        return $this->dm()->findType('code', $id) ?? $this->fail("no code {$id}");
    }

    private function idOf(string $code): string
    {
        foreach ($this->dm()->queryType('code')->get()->items() as $record) {
            if ($record->get('code') === $code) {
                return (string) $record->getId();
            }
        }
        $this->fail("no code {$code}");
    }

    private function login(): void
    {
        $f = new Psr17Factory();
        $page = (string) $this->app()->handle($f->createServerRequest('GET', '/folio/login'))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $m);
        $this->token = $m[1] ?? '';
        $login = $this->app()->handle($f->createServerRequest('POST', '/folio/login')->withParsedBody([
            '_csrf_token' => $this->token, 'email' => 'admin@site.test', 'password' => 'secret',
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

        return $this->app()->handle($req);
    }

    /** @return array<string, mixed> a create form post */
    private function create(array $over = []): array
    {
        return $over + ['purpose' => 'members.book', 'code' => 'smart-stack 2026', 'label' => 'Book', 'max_uses' => '3'];
    }

    #[RunInSeparateProcess]
    public function test_codes_listed_under_library(): void
    {
        $this->app();
        $this->login();

        $page = (string) $this->send('GET', '/folio/code')->getBody();
        $this->assertMatchesRegularExpression(
            '#<span class="folio-nav-label">Library</span>.*<a href="/folio/code"[^>]*>\s*<svg class="folio-icon" aria-hidden="true"><use href="[^"]*\#ui-key"></use></svg><span>Codes</span>#s',
            $page,
        );

        $form = (string) $this->send('GET', '/folio/code/new')->getBody();
        $this->assertStringContainsString('<option value="members.book"', $form);
        $this->assertStringContainsString('Force Map round', $form);
    }

    #[RunInSeparateProcess]
    public function test_code_list_filters_by_expiry_and_form(): void
    {
        $this->app();
        $this->login();
        $this->assertSame(302, $this->send('POST', '/folio/code', $this->create())->getStatusCode());

        $page = (string) $this->send('GET', '/folio/code')->getBody();
        $this->assertStringContainsString('name="f[expires_at][from]"', $page);
        $this->assertStringContainsString('name="f[form]"', $page);

        // Only the table body: the debug toolbar's query log also shows the code.
        $rows = fn (string $uri): string => preg_match('#<tbody.*?</tbody>#s', (string) $this->send('GET', $uri)->getBody(), $m) === 1 ? $m[0] : '';
        $this->assertStringContainsString('SMARTSTACK2026', $rows('/folio/code?f[form]=readable'));
        $this->assertStringNotContainsString('SMARTSTACK2026', $rows('/folio/code?f[form]=random'));
    }

    #[RunInSeparateProcess]
    public function test_create_readable_code_in_admin(): void
    {
        $this->app();
        $this->login();

        $res = $this->send('POST', '/folio/code', $this->create());
        $this->assertSame(302, $res->getStatusCode());
        $record = $this->stored($this->idOf('SMARTSTACK2026'));
        $this->assertSame('readable', $record->get('form'));
        $this->assertSame(0, (int) $record->get('uses'));
        $this->assertSame('', (string) $record->get('revoked_at'));
        $this->assertSame('members.book', $record->get('purpose'));

        // The issued code works like one from Codes::issue().
        $this->assertTrue($this->codes()->check('Smart Stack 2026', 'members.book')->ok);
    }

    /** A whole-number string or '' (unlimited) is what Codes compares; the number field may send '3.0'. */
    #[RunInSeparateProcess]
    public function test_max_uses_saved_as_whole_number_or_empty(): void
    {
        $this->app();
        $this->login();

        $this->assertSame(302, $this->send('POST', '/folio/code', $this->create(['max_uses' => '3.0']))->getStatusCode());
        $id = $this->idOf('SMARTSTACK2026');
        $this->assertSame('3', $this->stored($id)->get('max_uses'));

        $this->assertSame(302, $this->send('POST', "/folio/code/{$id}", ['label' => 'Book', 'max_uses' => ' 5.0 '])->getStatusCode());
        $this->assertSame('5', $this->stored($id)->get('max_uses'));

        $this->assertSame(302, $this->send('POST', "/folio/code/{$id}", ['label' => 'Book', 'max_uses' => ''])->getStatusCode());
        $this->assertSame('', (string) $this->stored($id)->get('max_uses'));

        $res = $this->send('POST', "/folio/code/{$id}", ['label' => 'Book', 'max_uses' => '2.5']);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('whole number', (string) $res->getBody());
    }

    #[RunInSeparateProcess]
    public function test_create_for_non_readable_purpose_rejected(): void
    {
        $this->app();
        $this->login();

        $res = $this->send('POST', '/folio/code', $this->create(['purpose' => 'forcemap.round']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('does not allow readable codes', (string) $res->getBody());

        foreach (['', ' - ', 'ab'] as $bad) {
            $res = $this->send('POST', '/folio/code', $this->create(['code' => $bad]));
            $this->assertSame(422, $res->getStatusCode(), "code [{$bad}]");
        }
        $this->assertCount(0, $this->dm()->queryType('code')->get()->items());
    }

    #[RunInSeparateProcess]
    public function test_duplicate_code_rejected(): void
    {
        $this->app();
        $this->login();

        $this->assertSame(302, $this->send('POST', '/folio/code', $this->create())->getStatusCode());
        $res = $this->send('POST', '/folio/code', $this->create(['code' => 'SMARTSTACK2026']));
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('already exists', (string) $res->getBody());
        $this->assertCount(1, $this->dm()->queryType('code')->get()->items());
    }

    #[RunInSeparateProcess]
    public function test_update_cannot_change_code_or_uses(): void
    {
        $codes = $this->codes();
        $codes->issue('forcemap.round', ['team' => 't1'], label: 'Round 1');
        $codes->issue('members.book', ['edition' => 1], 'BOOKCODE1', 'Book');
        $id = $this->idOf('BOOKCODE1');
        $codes->redeem('BOOKCODE1', 'members.book', 'member-1');
        $before = $this->stored($id);
        $this->login();

        $res = $this->send('POST', "/folio/code/{$id}", [
            'purpose' => 'forcemap.round', 'code' => 'HACKED99', 'label' => 'New label', 'expires_at' => '2030-01-02T10:30', 'max_uses' => '9',
            'uses' => '0', 'revoked_at' => '2020-01-01', 'payload' => '{"x":1}', 'form' => 'random',
        ]);
        $this->assertSame(302, $res->getStatusCode());

        $after = $this->stored($id);
        $this->assertSame('New label', $after->get('label'));
        $this->assertSame(9, (int) $after->get('max_uses'));
        $this->assertStringStartsWith('2030-01-02', (string) $after->get('expires_at'));
        foreach (['code', 'purpose', 'form', 'payload'] as $field) {
            $this->assertSame($before->get($field), $after->get($field), $field);
        }
        $this->assertSame(1, (int) $after->get('uses'));
        $this->assertSame('', (string) ($after->get('revoked_at') ?? ''));
    }

    #[RunInSeparateProcess]
    public function test_counters_and_state_read_only_payload_hidden(): void
    {
        $this->codes()->issue('members.book', ['edition' => 1], 'BOOKCODE1', 'Book');
        $id = $this->idOf('BOOKCODE1');
        $this->login();

        $form = (string) $this->send('GET', "/folio/code/{$id}/edit")->getBody();
        foreach (['uses', 'revoked_at', 'form', 'payload'] as $field) {
            $this->assertStringNotContainsString('name="' . $field . '"', $form, $field);
        }
        $this->assertStringContainsString('<span class="form-label">Uses</span>', $form);
        $this->assertStringContainsString('<span class="form-label">Form</span>', $form);
        $this->assertStringNotContainsString('Payload', $form);
        $this->assertStringContainsString('name="code"', $form); // the code stays an input (required on create)

        // A payload typed into a create post is ignored.
        $this->assertSame(302, $this->send('POST', '/folio/code', $this->create(['payload' => '{"grant":"all"}']))->getStatusCode());
        $this->assertSame([], $this->codes()->check('Smart Stack 2026', 'members.book')->payload);
    }

    #[RunInSeparateProcess]
    public function test_revoke_action(): void
    {
        $this->codes()->issue('members.book', [], 'BOOKCODE1');
        $id = $this->idOf('BOOKCODE1');
        $this->login();

        $form = (string) $this->send('GET', "/folio/code/{$id}/edit")->getBody();
        $this->assertStringContainsString("/folio/code/{$id}/_action/revoke", $form);
        $this->assertStringContainsString('Revoke this code? It stops working at once.', $form);

        $res = $this->send('POST', "/folio/code/{$id}/_action/revoke");
        $this->assertSame(302, $res->getStatusCode());
        $this->assertNotSame('', (string) $this->stored($id)->get('revoked_at'));
        $this->assertSame('revoked', $this->codes()->check('BOOKCODE1', 'members.book')->reason);
    }

    #[RunInSeparateProcess]
    public function test_redemptions_panel_and_column(): void
    {
        $codes = $this->codes();
        $codes->issue('members.book', [], 'BOOKCODE1');
        $codes->issue('members.book', [], 'BOOKCODE2');
        $codes->issue('members.book', [], 'BOOKCODE3', expiresAt: new \DateTimeImmutable('2020-01-01'));
        $codes->issue('members.book', [], 'BOOKCODE4');
        $codes->redeem('BOOKCODE1', 'members.book', 'member-77');
        $codes->redeem('BOOKCODE1', 'members.book', 'member-78');
        $codes->revoke($this->idOf('BOOKCODE4'));
        $id = $this->idOf('BOOKCODE1');
        $this->login();

        $list = (string) $this->send('GET', '/folio/code')->getBody();
        $this->assertStringContainsString('Redemptions', $list);
        $this->assertMatchesRegularExpression('#BOOKCODE1.*?<td[^>]*>\s*2\s*</td>#s', $list);
        $this->assertMatchesRegularExpression('#BOOKCODE2.*?<td[^>]*>\s*0\s*</td>#s', $list);
        $this->assertMatchesRegularExpression('#BOOKCODE3.*?<td[^>]*>\s*expired\s*</td>#s', $list);
        $this->assertMatchesRegularExpression('#BOOKCODE4.*?<td[^>]*>\s*revoked\s*</td>#s', $list);

        $form = (string) $this->send('GET', "/folio/code/{$id}/edit")->getBody();
        $this->assertStringContainsString('member-77', $form);
        $this->assertStringContainsString('member-78', $form);

        $empty = (string) $this->send('GET', '/folio/code/' . $this->idOf('BOOKCODE2') . '/edit')->getBody();
        $this->assertStringContainsString('No redemptions yet.', $empty);
    }
}
