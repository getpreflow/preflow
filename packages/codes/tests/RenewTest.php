<?php

declare(strict_types=1);

namespace Preflow\Codes\Tests;

final class RenewTest extends CodesAppTestCase
{
    public function test_renews_an_expired_open_code(): void
    {
        $codes = $this->codes();
        $c = $codes->issue('q', expiresAt: $this->now->modify('+1 hour'));
        $id = (string) $codes->check($c, 'q')->codeId;
        $this->now = $this->now->modify('+2 hours');
        $this->assertSame('expired', $codes->check($c, 'q')->reason);

        $until = $this->now->modify('+7 days');
        $codes->renew($id, $until);

        $this->assertTrue($codes->check($c, 'q')->ok);
        $this->assertEquals($until, $codes->get($id)->expiresAt);
    }

    public function test_refuses_revoked_used_up_unknown_and_past_dates(): void
    {
        $codes = $this->codes();
        $revoked = $codes->issue('q');
        $revokedId = (string) $codes->check($revoked, 'q')->codeId;
        $codes->revoke($revokedId);
        $once = $codes->issue('q', maxUses: 1);
        $onceId = (string) $codes->check($once, 'q')->codeId;
        $codes->redeem($once, 'q');
        $open = $codes->issue('q');
        $openId = (string) $codes->check($open, 'q')->codeId;
        $future = $this->now->modify('+1 day');

        foreach ([[$revokedId, $future], [$onceId, $future], ['nope', $future], [$openId, $this->now], [$openId, $this->now->modify('-1 day')]] as [$id, $at]) {
            try {
                $codes->renew($id, $at);
                $this->fail('renew should refuse');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** renew() and revoke() write their one column: a use counted meanwhile is never overwritten. */
    public function test_renew_and_revoke_write_only_their_column(): void
    {
        $codes = $this->codes();
        $dm = $this->app()->container()->get(\Preflow\Data\DataManager::class);
        $dm->execute('CREATE TABLE touched (col TEXT)', [], 'default');
        // SQLite saves with INSERT OR REPLACE: any whole-record write is an INSERT.
        $dm->execute("CREATE TRIGGER t_ins AFTER INSERT ON code BEGIN INSERT INTO touched VALUES ('insert'); END", [], 'default');
        $a = $codes->issue('q');
        $aId = (string) $codes->check($a, 'q')->codeId;
        $b = $codes->issue('q');
        $bId = (string) $codes->check($b, 'q')->codeId;

        $dm->execute('DELETE FROM touched', [], 'default'); // the issues above inserted
        $codes->renew($aId, $this->now->modify('+3 days'));
        $codes->revoke($bId);

        $touched = $dm->driver('default')->pdo()->query('SELECT col FROM touched')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], $touched);
        $this->assertNotNull($codes->get($bId)->revokedAt);
        $this->assertEquals($this->now->modify('+3 days'), $codes->get($aId)->expiresAt);
    }
}
