<?php

declare(strict_types=1);

namespace Preflow\Folio\Tests\Members;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Preflow\Auth\Access\Access;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Data\TypeRegistry;
use Preflow\Folio\Content\ContentLocale;
use Preflow\Folio\Content\TypeCatalog;
use Preflow\Folio\Members\FolioMembersSettings;
use Preflow\Folio\Tests\Members\Support\MembersScaffold;
use Preflow\Members\ConfigMembersSettings;
use Preflow\Members\MembersConfig;
use Preflow\Members\MembersSettings;
use Preflow\Members\MemberType;
use Preflow\Members\Tests\Support\MemberFlows;
use Preflow\Members\Viewer;
use Preflow\Rbac\RbacResolver;

/**
 * The members core on a Folio site: Folio's members admin takes the core's member type and adds
 * what its admin needs, binds its settings whatever the provider order, rbac keeps deciding
 * access, staff and members share the site, and the public pages are those of before the split.
 */
final class MembersCoreInFolioTest extends TestCase
{
    use MemberFlows;
    use MembersScaffold;

    /** The registration page of v0.36.0, the members core's own snapshot. */
    private const string SNAPSHOT = __DIR__ . '/../../../members/tests/Fixtures/register-de-v0.36.0.html';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            $this->removeScaffold();
        }
    }

    /** @return list<string> */
    private static function orders(): array
    {
        return [
            'members last' => "[\\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class, \\Preflow\\Members\\MembersServiceProvider::class]",
            'members first' => "[\\Preflow\\Members\\MembersServiceProvider::class, \\Preflow\\Folio\\FolioServiceProvider::class, \\Preflow\\Rbac\\RbacServiceProvider::class]",
        ];
    }

    public function test_folio_binds_its_settings_and_rbac_decides_in_either_provider_order(): void
    {
        foreach (self::orders() as $what => $providers) {
            $this->scaffold();
            $this->write('config/providers.php', "<?php\nreturn {$providers};\n");
            $this->migrate();
            $c = $this->app()->container();

            $this->assertInstanceOf(FolioMembersSettings::class, $c->get(MembersSettings::class), $what);
            $resolver = (new \ReflectionProperty(Access::class, 'resolver'))->getValue($c->get(Access::class));
            $this->assertInstanceOf(RbacResolver::class, $resolver, $what);
            $this->removeScaffold();
        }
        $this->dir = '';
    }

    public function test_with_the_members_admin_switched_off_the_core_reads_config(): void
    {
        $this->scaffold();
        $this->write('config/folio/extensions.json', '{"enabled": {"rbac": {"disabled_parts": []}}}');
        $this->write('config/members.php', "<?php\nreturn ['registration_open' => false];\n");
        $this->migrate();

        $settings = $this->container()->get(MembersSettings::class);

        $this->assertInstanceOf(ConfigMembersSettings::class, $settings);
        $this->assertFalse($settings->registrationOpen());
    }

    public function test_the_admins_member_type_is_the_cores_with_the_admins_additions(): void
    {
        $this->scaffold(locales: ['de', 'fr']);
        $this->migrate();
        $c = $this->container();

        $core = MemberType::schema();
        $def = $c->get(TypeRegistry::class)->get(MembersConfig::TYPE);
        $this->assertSame(array_keys($core['fields']), array_keys($def->fields), 'the core\'s fields, in its order');
        $this->assertSame('rbac_roles', $def->fields['roles']->type, 'the admin edits roles as rbac roles');
        $this->assertSame('list', $core['fields']['roles']['type'], 'the core keeps a plain list');
        $this->assertSame(MembersConfig::STORAGE, $def->storage);
        $this->assertSame(['de', 'fr'], array_keys((array) $def->fields['language']->config['options']));

        $listing = $c->get(TypeCatalog::class)->get(MembersConfig::TYPE);
        $this->assertSame('members', $listing?->section);
        $this->assertSame('members', $listing?->users);
        $this->assertSame(['email', 'name', 'registered_at'], $listing?->list['columns'] ?? null);
    }

    #[RunInSeparateProcess]
    public function test_the_german_registration_page_is_still_the_one_of_v0_36_0(): void
    {
        $this->flowSite(['admission' => \Preflow\Members\Admission\OwnerApproval::class]);

        $page = $this->send('GET', '/de/members/register');
        $errors = $this->post('/de/members/register', ['name' => '', 'email' => 'nope', 'password' => 'short']);
        $both = self::stable((string) $page->getBody()) . "\n<!-- errors -->\n" . self::stable((string) $errors->getBody());

        $this->assertSame((string) file_get_contents(self::SNAPSHOT), $both);
    }

    #[RunInSeparateProcess]
    public function test_a_member_page_sets_folios_content_locale(): void
    {
        $this->flowSite();

        $this->send('GET', '/de/members/login');

        $this->assertSame('de', $this->flow->container()->get(ContentLocale::class)->get());
    }

    #[RunInSeparateProcess]
    public function test_staff_is_sent_to_the_members_home(): void
    {
        $this->flowSite();
        $this->signIn();

        $response = $this->send('GET', '/members/account');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
    }

    #[RunInSeparateProcess]
    public function test_logout_keeps_staff_session(): void
    {
        $this->flowSite();
        $this->makeMember();
        $this->signIn();
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'staff is in');
        $this->post('/members/login', ['email' => 'ada@example.com', 'password' => self::PASSWORD]);

        $response = $this->post('/members/logout', []);

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/members', $response->getHeaderLine('Location'));
        $this->assertStringContainsString('site_member=; Max-Age=0', $this->cookie($response, 'site_member') ?? '');
        $this->assertSame(200, $this->send('GET', '/folio')->getStatusCode(), 'staff stays signed in');
        $this->assertNull($this->flow->container()->get(Viewer::class)->member());
    }

    #[RunInSeparateProcess]
    public function test_a_member_declined_in_folio_with_a_remember_cookie_loses_access(): void
    {
        $this->flowSite();
        $m = $this->makeMember();
        $cookie = $this->flow->container()->get(RememberMe::class)->issue('members', (string) $m->getId());
        $this->assertSame(200, $this->send('GET', '/members/account', cookies: ['site_member' => $cookie])->getStatusCode(), 'the remember cookie opens the account');

        $this->signIn();
        $page = $this->admin('/folio/member/' . $m->getId() . '/edit');
        preg_match('/name="_csrf_token" value="([^"]+)"/', $page, $t);
        $this->flow->handle($this->request('POST', '/folio/member/' . $m->getId() . '/_action/decline', ['message' => 'Sorry.', '_csrf_token' => $t[1] ?? '']));
        unset($_SESSION);
        $this->flow = $this->app(); // a fresh request: only the cookie

        $this->assertSame(303, $this->send('GET', '/members/account', cookies: ['site_member' => $cookie])->getStatusCode(), 'a declined member is out');
    }

    private static function stable(string $html): string
    {
        return (string) preg_replace(['/(name="_csrf_token" value=")[^"]+/', '/(nonce=")[^"]+/', '/(Nonce":")[^"]+/'], '$1…', $html);
    }
}
