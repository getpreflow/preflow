<?php

declare(strict_types=1);

namespace Preflow\Members\Tests;

use PHPUnit\Framework\TestCase;
use Preflow\Data\DataManager;
use Preflow\DevTools\Console;
use Preflow\Members\Console\PruneMembersCommand;
use Preflow\Members\Tests\Support\MembersScaffold;

/** members:prune: unconfirmed accounts go after 7 days, declined ones after 30; waiting accounts and members stay. */
final class PruneMembersTest extends TestCase
{
    use MembersScaffold;

    protected function setUp(): void
    {
        $this->scaffold();
    }

    protected function tearDown(): void
    {
        $this->removeScaffold();
    }

    public function test_prunes_unconfirmed_after_7_days_and_declined_after_30(): void
    {
        $this->migrateAndApply();
        $this->member('u-6', [], 6);
        $this->member('u-8', [], 8);
        $this->member('d-29', ['rejected'], 29, decided: 29);
        $this->member('d-31', ['rejected'], 31, decided: 31);

        $out = $this->prune();

        $this->assertSame("members:prune — deleted 1 unconfirmed, 1 declined; pruned tokens, remember, throttle\n", $out);
        $this->assertSame(['d-29', 'u-6'], $this->ids());
    }

    public function test_never_prunes_waiting_or_members(): void
    {
        $this->migrateAndApply();
        $this->member('waiting', ['pending'], 90, confirmed: 90);
        $this->member('member', ['member'], 90, confirmed: 90, decided: 90);
        $this->member('undated', [], 0, registered: false);

        $out = $this->prune();

        $this->assertStringContainsString('deleted 0 unconfirmed, 0 declined', $out);
        $this->assertSame(['member', 'undated', 'waiting'], $this->ids());
    }

    public function test_retention_comes_from_config(): void
    {
        $this->write('config/members.php', "<?php\nreturn ['retention' => ['unconfirmed_days' => 2, 'declined_days' => 3]];\n");
        $this->migrateAndApply();
        $this->member('u-1', [], 1);
        $this->member('u-3', [], 3);
        $this->member('d-4', ['rejected'], 9, decided: 4);

        $this->assertStringContainsString('deleted 1 unconfirmed, 1 declined', $this->prune());
        $this->assertSame(['u-1'], $this->ids());
    }

    public function test_command_is_registered_by_the_package(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.json'), true);
        $this->write('vendor/composer/installed.json', (string) json_encode(['packages' => [['name' => 'preflow/members', 'extra' => $composer['extra']]]]));

        ob_start();
        (new Console($this->dir))->run(['preflow', 'help']);
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('members:prune', $out);
    }

    private function prune(): string
    {
        [$code, $out] = $this->command(new PruneMembersCommand(fn () => $this->app()));
        $this->assertSame(0, $code);

        return $out;
    }

    /** @param list<string> $roles */
    private function member(string $id, array $roles, int $registeredDaysAgo, ?int $confirmed = null, ?int $decided = null, bool $registered = true): void
    {
        $ago = static fn (?int $d): string => $d === null ? '' : (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-{$d} days")->format(\DATE_ATOM);
        $this->saveRecord('member', [
            'uuid' => $id, 'email' => $id . '@site.test', 'name' => $id, 'password_hash' => 'x', 'roles' => $roles,
            'registered_at' => $registered ? $ago($registeredDaysAgo) : '',
            'email_confirmed_at' => $ago($confirmed), 'decided_at' => $ago($decided),
        ]);
    }

    /** @return list<string> */
    private function ids(): array
    {
        $ids = array_map(static fn ($m): string => (string) $m->getId(), iterator_to_array($this->container()->get(DataManager::class)->queryType('member')->get(), false));
        sort($ids);

        return $ids;
    }
}
