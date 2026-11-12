<?php

declare(strict_types=1);

namespace Preflow\Auth\Tests\Record;

use Preflow\Auth\NativePasswordHasher;
use Preflow\Auth\Record\AccountLinks;
use Preflow\Auth\Record\RecordUserProvider;
use Preflow\Auth\Remember\RememberMe;
use Preflow\Auth\Tokens\Tokens;
use Preflow\Data\DynamicRecord;
use Preflow\Data\Migration\Migration;
use Preflow\Data\Migration\Schema;
use Preflow\Data\TypeDefinition;
use Preflow\Data\TypeFieldDefinition;
use Preflow\Testing\DataTestCase;

/** Password links of record accounts: tokens, a new password, voided links and devices. */
final class AccountLinksTest extends DataTestCase
{
    private const string GUARD = 'staff';

    private int $now = 1_000_000;
    private string $lockDir;
    private TypeDefinition $staff;
    private RememberMe $remember;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['2026_10_23_000000_auth_tokens.php', '2026_10_23_000001_auth_remember.php'] as $file) {
            /** @var Migration $migration */
            $migration = require dirname(__DIR__, 2) . '/migrations/' . $file;
            $migration->up(new Schema($this->getPdo()));
        }
        $this->getPdo()->exec('CREATE TABLE staff (uuid TEXT PRIMARY KEY, email TEXT, name TEXT, password_hash TEXT, roles TEXT, auth_epoch TEXT, active INTEGER)');
        $this->staff = new TypeDefinition(
            key: 'staff',
            table: 'staff',
            storage: 'sqlite',
            fields: [
                'email' => new TypeFieldDefinition('email', 'string'),
                'name' => new TypeFieldDefinition('name', 'string'),
                'password_hash' => new TypeFieldDefinition('password_hash', 'string'),
                'roles' => new TypeFieldDefinition('roles', 'string'),
                'auth_epoch' => new TypeFieldDefinition('auth_epoch', 'string'),
                'active' => new TypeFieldDefinition('active', 'boolean'),
            ],
            idField: 'uuid',
        );
        $this->lockDir = sys_get_temp_dir() . '/preflow_links_locks_' . uniqid();
        $this->remember = new RememberMe($this->dataManager(), 'default', fn (): int => $this->now, $this->lockDir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->lockDir . '/*') ?: []);
        @rmdir($this->lockDir);
        parent::tearDown();
    }

    private function links(): AccountLinks
    {
        $provider = new RecordUserProvider(
            $this->dataManager(),
            ['type' => 'staff', 'active' => 'active'],
            fn (string $type): ?TypeDefinition => $type === 'staff' ? $this->staff : null,
        );

        return new AccountLinks(
            new Tokens($this->dataManager(), 'default', fn (): int => $this->now, $this->lockDir),
            $this->remember,
            new NativePasswordHasher(),
            $this->dataManager(),
            $provider,
            [$provider],
            self::GUARD,
        );
    }

    private function account(string $id = 's1', bool $active = true, string $hash = ''): DynamicRecord
    {
        $record = new DynamicRecord($this->staff, [
            'uuid' => $id, 'email' => $id . '@site.test', 'name' => 'Ada', 'password_hash' => $hash,
            'roles' => '[]', 'auth_epoch' => '2', 'active' => $active,
        ]);
        $this->dataManager()->saveType($record, false);

        return $record;
    }

    private function stored(string $id, string $field): string
    {
        return (string) $this->dataManager()->findIn($this->staff, $id)?->get($field);
    }

    public function test_a_link_gives_its_account_once(): void
    {
        $links = $this->links();
        $token = $links->issue($this->account(), 'site.reset', 3600);

        $this->assertNull($links->consume($token, 'site.invite'), 'another purpose');
        $this->assertSame('s1', (string) $links->consume($token, 'site.reset')?->getId());
        $this->assertNull($links->consume($token, 'site.reset'), 'used up');
        $this->assertNull($links->consume('', 'site.reset'));
        $this->assertNull($links->consume('not-a-token', 'site.reset'));
    }

    public function test_an_expired_link_is_refused(): void
    {
        $links = $this->links();
        $token = $links->issue($this->account(), 'site.reset', 3600);

        $this->now += 3600;

        $this->assertNull($links->consume($token, 'site.reset'));
    }

    public function test_the_link_of_an_inactive_account_is_refused(): void
    {
        $links = $this->links();
        $token = $links->issue($this->account(active: false), 'site.invite', 3600);

        $this->assertNull($links->consume($token, 'site.invite'));
    }

    public function test_the_subject_names_type_and_id(): void
    {
        $this->assertSame('staff:s1', AccountLinks::subject($this->account()));
    }

    public function test_revoke_voids_the_links_of_the_purposes_given(): void
    {
        $links = $this->links();
        $account = $this->account();
        $other = $this->account('s2');
        $earlier = $links->issue($account, 'site.invite', 3600);
        $reset = $links->issue($account, 'site.reset', 3600);
        $others = $links->issue($other, 'site.invite', 3600);

        $links->revoke($account, 'site.invite');
        $later = $links->issue($account, 'site.invite', 3600);

        $this->assertNull($links->consume($earlier, 'site.invite'), 'the earlier invite is void');
        $this->assertSame('s1', (string) $links->consume($later, 'site.invite')?->getId());
        $this->assertSame('s1', (string) $links->consume($reset, 'site.reset')?->getId(), 'another purpose stays');
        $this->assertSame('s2', (string) $links->consume($others, 'site.invite')?->getId(), 'another account stays');
    }

    public function test_set_password_saves_the_hash_and_moves_the_epoch(): void
    {
        $links = $this->links();
        $account = $this->account();
        $this->remember->issue(self::GUARD, 's1');
        $this->remember->issue('member', 's1');
        // A stale copy: a Disable or a rename made meanwhile is not written back.
        $this->dataManager()->saveType(new DynamicRecord($this->staff, ['name' => 'Ada Lovelace'] + $account->toArray()), false);

        $this->assertTrue($links->setPassword($account, 'a brand new password'));

        $this->assertSame('3', $this->stored('s1', 'auth_epoch'));
        $this->assertSame('Ada Lovelace', $this->stored('s1', 'name'));
        $fresh = $this->dataManager()->findIn($this->staff, 's1');
        $this->assertNotNull($fresh);
        $this->assertTrue($links->verify($fresh, 'a brand new password'));
        $this->assertFalse($links->verify($fresh, 'the old one'));
        $this->assertSame(0, $this->remember->revokeAll(self::GUARD, 's1'), "the guard's devices are forgotten");
        $this->assertSame(1, $this->remember->revokeAll('member', 's1'), "another guard's stay");
    }

    public function test_set_password_saves_nothing_for_an_inactive_account(): void
    {
        $links = $this->links();
        $account = $this->account(active: false);

        $this->assertFalse($links->setPassword($account, 'a brand new password'));
        $this->assertSame('', $this->stored('s1', 'password_hash'));
        $this->assertSame('2', $this->stored('s1', 'auth_epoch'));
    }

    public function test_an_account_without_a_password_verifies_none(): void
    {
        $this->assertFalse($this->links()->verify($this->account(), ''));
    }

    public function test_find_active_by_email(): void
    {
        $links = $this->links();
        $this->account();
        $this->account('s2', active: false);

        $this->assertSame('s1', (string) $links->findActive('  S1@Site.test ')?->getId());
        $this->assertNull($links->findActive('s2@site.test'), 'inactive');
        $this->assertNull($links->findActive('nobody@site.test'));
        $this->assertNull($links->findActive(''));
    }

    public function test_account_reads_the_account_afresh(): void
    {
        $links = $this->links();
        $account = $this->account();
        $this->dataManager()->saveType(new DynamicRecord($this->staff, ['auth_epoch' => '5'] + $account->toArray()), false);

        $this->assertSame('5', $links->account($account)?->getSessionEpoch());
        $this->assertNull($links->account($this->account('s2', active: false)));
    }

    public function test_a_record_of_another_type_is_refused(): void
    {
        $member = new TypeDefinition(key: 'member', table: 'member', storage: 'sqlite', fields: [], idField: 'uuid');

        $this->expectException(\InvalidArgumentException::class);
        $this->links()->issue(new DynamicRecord($member, ['uuid' => 'm1']), 'site.reset', 3600);
    }
}
