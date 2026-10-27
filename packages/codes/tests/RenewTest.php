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
}
