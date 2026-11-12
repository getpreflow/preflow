<?php

declare(strict_types=1);

namespace Preflow\Rbac\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Record\AccountLinks;
use Preflow\Core\Application;
use Preflow\Data\DataManager;
use Preflow\Folio\Users\StaffPasswords;
use Preflow\Notify\Email;
use Preflow\Notify\TransportFactory;
use Preflow\Rbac\Console\StaffDisableCommand;
use Preflow\Rbac\Console\StaffInviteCommand;
use Preflow\Rbac\Staff\StaffAccounts;
use Preflow\Rbac\Staff\StaffLinks;
use Preflow\Rbac\Staff\StaffPopulation;

/**
 * The staff commands on a Folio site: they use Folio's staff guard, an invite mailed from a
 * command opens Folio's set-password page (and names no Folio), and one AccountLinks serves
 * Folio and rbac. Accounts: boss@ (admin), new@ (no password yet).
 */
final class StaffCommandsInFolioTest extends TestCase
{
    use RbacAppScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    private function folioSite(): void
    {
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": [], "enabled_parts": ["model:staff"]}}}');
        $this->write('config/auth.php', "<?php\nreturn " . var_export([
            'default_guard' => 'session',
            'guards' => ['session' => ['class' => \Preflow\Auth\SessionGuard::class, 'provider' => 'staff', 'population' => 'staff']],
            'providers' => ['staff' => ['class' => \Preflow\Auth\Record\RecordUserProvider::class, 'type' => 'staff', 'active' => 'active']],
            'session' => ['cookie' => 'rbac_folio_commands_test', 'path' => '/', 'secure' => false],
        ], true) . ";\n");
        $this->write('config/app.php', "<?php\nreturn ['name' => 'Rbac', 'debug' => 1, 'engine' => 'twig', 'key' => 'rbac-app-test-key', 'url' => 'https://site.test'];\n");
        $this->write('config/notify.php', "<?php\nreturn ['transports' => ['mail' => ['driver' => 'memory']], 'channels' => ['email' => ['transport' => 'mail', 'from' => 'site@site.test']]];\n");
        $this->install();
        $accounts = $this->container()->get(StaffAccounts::class);
        $accounts->create('boss@site.test', 'Boss', ['admin']);
        $accounts->create('new@site.test', 'Newbie', []);
    }

    /** @return list<Email> */
    private function mails(): array
    {
        return array_values(array_map(static fn ($s) => $s->message, $this->container()->get(TransportFactory::class)->get('mail')->sent()));
    }

    #[RunInSeparateProcess]
    public function test_an_invite_from_a_command_opens_folio_s_set_password_page_and_names_no_folio(): void
    {
        $this->folioSite();
        $command = new StaffInviteCommand(fn (): Application => $this->session ??= $this->app());
        ob_start();
        $code = $command->execute(['new@site.test']);
        $out = (string) ob_get_clean();

        $this->assertSame([0, "Invitation sent to new@site.test.\n"], [$code, $out]);
        $mail = $this->mails()[0];
        $this->assertSame('Your account for Rbac', $mail->subject);
        foreach ([$mail->subject, $mail->text, (string) $mail->html] as $part) {
            $this->assertStringNotContainsStringIgnoringCase('folio', preg_replace('~https://site\.test/folio/password/[A-Za-z0-9_-]+~', '', $part));
        }
        preg_match('~https://site\.test/folio/password/([A-Za-z0-9_-]+)~', $mail->text, $m);
        $token = $m[1] ?? $this->fail('no link to Folio\'s set-password page');

        // Folio's page takes the token and signs the account in.
        $f = new Psr17Factory();
        $page = (string) $this->session->handle($f->createServerRequest('GET', '/folio/password/' . $token))->getBody();
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $csrf);
        $this->token = $csrf[1] ?? '';
        $res = $this->send('POST', '/folio/password', ['token' => $token, 'password' => 'a brand new password', 'repeat' => 'a brand new password']);
        $this->assertSame('/folio', $res->getHeaderLine('Location'));
        $this->assertNotSame('', (string) $this->container()->get(StaffAccounts::class)->find('new@site.test')?->get('password_hash'));
    }

    #[RunInSeparateProcess]
    public function test_rbac_uses_folio_s_staff_guard_links_and_page(): void
    {
        $this->folioSite();
        $c = $this->container();

        $this->assertSame('session', $c->get(StaffPopulation::class)->guard);
        $this->assertSame('session', $c->get(StaffAccounts::class)->guard());
        $this->assertSame('https://site.test/folio/password/abc', $c->get(StaffLinks::class)->setPasswordUrl('abc'));
        $passwords = $c->get(StaffPasswords::class);
        $links = (new \ReflectionProperty(StaffPasswords::class, 'links'))->getValue($passwords);
        $this->assertSame($c->get(AccountLinks::class), $links, 'one AccountLinks for Folio and rbac');
    }

    #[RunInSeparateProcess]
    public function test_the_last_admin_can_t_be_disabled_from_a_command_on_a_folio_site(): void
    {
        $this->folioSite();
        $command = new StaffDisableCommand(fn (): Application => $this->session ??= $this->app());
        ob_start();
        $code = $command->execute(['boss@site.test']);
        $out = (string) ob_get_clean();

        $this->assertSame([1, "At least one account must keep the right to manage staff and roles.\n"], [$code, $out]);
    }

    #[RunInSeparateProcess]
    public function test_config_rbac_may_not_name_another_guard_than_folio_s(): void
    {
        $this->write('config/rbac.php', "<?php\nreturn ['guard' => 'other'];\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("config/rbac.php 'guard' names \"other\", but the admin signs staff in through the guard \"session\".");
        $this->app();
    }
}
